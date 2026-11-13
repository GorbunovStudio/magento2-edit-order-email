<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Test\Unit\Controller\Adminhtml\Edit;

use Budsies\Sales\Service\CustomerProvider;
use Budsies\Sales\Service\NewCustomerCreator;
use Error;
use MagePal\EditOrderEmail\Controller\Adminhtml\Edit\Index;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Store\Api\Data\StoreInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class IndexTest extends TestCase
{
    public function testExistingCustomerAssignmentForGuestAndRegisteredOrders(): void
    {
        foreach ([0, 7] as $customerId) {
            [$response, $trace, $order] = $this->runAction([
                'customerId' => $customerId, 'existing' => true, 'assign' => true,
            ]);
            self::assertFalse($response['error']);
            self::assertSame('new@example.com', $response['email']);
            self::assertSame(99, $order->getCustomerId());
            self::assertSame(3, $order->getCustomerGroupId());
            self::assertSame(0, $order->getCustomerIsGuest());
            self::assertSame('new@example.com', $order->getCustomerEmail());
            self::assertSame(['lock', 'begin', 'load', 'save', 'commit', 'unlock'], $trace);
            self::assertSame('unchanged', $order->getData('unrelated'));
        }
    }

    public function testExistingCustomerRequiresAssignmentEvenWhenCreationRequested(): void
    {
        foreach ([0, 7] as $customerId) {
            [$response, $trace] = $this->runAction(['customerId' => $customerId, 'existing' => true, 'create' => true]);
            self::assertTrue($response['error']);
            self::assertStringContainsString('Customer with this email already exists.', $response['message']);
            self::assertSame(['lock', 'begin', 'load', 'rollback', 'unlock'], $trace);
        }
    }

    public function testCreationForGuestAndRegisteredOrders(): void
    {
        foreach ([0, 7] as $customerId) {
            [$response, $trace, $order] = $this->runAction([
                'customerId' => $customerId, 'create' => $customerId !== 0,
            ]);
            self::assertFalse($response['error']);
            self::assertSame(99, $order->getCustomerId());
            self::assertSame(3, $order->getCustomerGroupId());
            self::assertSame(0, $order->getCustomerIsGuest());
            self::assertSame('new@example.com', $order->getCustomerEmail());
            self::assertContains('create', $trace);
            self::assertNotContains('customer-save', $trace);
        }
    }

    public function testExistingAccountEmailUpdate(): void
    {
        [$response, $trace, $order] = $this->runAction(['customerId' => 7]);
        self::assertFalse($response['error']);
        self::assertContains('customer-save', $trace);
        self::assertSame(7, $order->getCustomerId());
        self::assertSame('new@example.com', $order->getCustomerEmail());
    }

    public function testInvalidSameStaleAndLockTimeout(): void
    {
        foreach ([
            [['invalid' => true], 'Invalid Email address.', []],
            [['same' => true], 'Email address is the same as the old one.',
                ['lock', 'begin', 'load', 'rollback', 'unlock']],
            [['stale' => true], 'Order not found or email mismatch.', ['lock', 'begin', 'load', 'rollback', 'unlock']],
            [['timeout' => true], 'Please try again.', ['lock']],
        ] as [$options, $message, $expectedTrace]) {
            [$response, $trace] = $this->runAction($options);
            self::assertTrue($response['error']);
            self::assertStringContainsString($message, (string)$response['message']);
            self::assertSame($expectedTrace, $trace);
        }
    }

    public function testActionableValidationAndDuplicateReasons(): void
    {
        $aggregate = new InputException();
        $aggregate->addError(new Phrase('Name is required'));
        $aggregate->addError(new Phrase('Address <invalid>'));
        foreach ([
            [new InputException(new Phrase('Name is required')), 'Name is required'],
            [$aggregate, 'Address &lt;invalid&gt;'],
            [new InputMismatchException(new Phrase('Email already exists')), 'Email already exists'],
            [new AlreadyExistsException(new Phrase('Email already exists')), 'Email already exists'],
        ] as [$exception, $message]) {
            [$response, $trace] = $this->runAction(['failure' => $exception]);
            self::assertTrue($response['error']);
            self::assertStringContainsString($message, $response['message']);
            self::assertSame('', $response['email']);
            self::assertFalse($response['ajaxExpired']);
            if ($exception === $aggregate) {
                self::assertStringContainsString('Name is required', $response['message']);
            }
            self::assertSame(['lock', 'begin', 'load', 'customer-save', 'rollback', 'unlock'], $trace);
        }
    }

    public function testTechnicalFailureAndNestedThrowableRollbackBeforeUnlock(): void
    {
        foreach ([
            new CouldNotSaveException(new Phrase('SQL secret')),
            new AlreadyExistsException(new Phrase('SQL secret'), new RuntimeException('SQL secret')),
            new Error('SQL secret'),
        ] as $exception) {
            [$response, $trace] = $this->runAction(['failure' => $exception, 'nested' => true]);
            self::assertTrue($response['error']);
            self::assertSame('Unable to change the email address.', (string)$response['message']);
            self::assertSame(['lock', 'begin', 'load', 'customer-save', 'rollback', 'rollback', 'unlock'], $trace);
        }
    }

    public function testOrderSaveFailureRollsBackAndUnlockFailureDoesNotUndoCommit(): void
    {
        [$response, $trace] = $this->runAction(['saveFailure' => true]);
        self::assertTrue($response['error']);
        self::assertSame(['lock', 'begin', 'load', 'customer-save', 'save', 'rollback', 'unlock'], $trace);
        [$response, $trace] = $this->runAction(['unlockFailure' => true]);
        self::assertFalse($response['error']);
        self::assertSame(['lock', 'begin', 'load', 'customer-save', 'save', 'commit', 'unlock'], $trace);
    }

    public function testCreationValidationAndBeginFailure(): void
    {
        [$response, $trace] = $this->runAction([
            'create' => true, 'creationFailure' => new InputMismatchException(new Phrase('Email already exists')),
        ]);
        self::assertTrue($response['error']);
        self::assertSame('Email already exists', $response['message']);
        self::assertSame(['lock', 'begin', 'load', 'create', 'rollback', 'unlock'], $trace);
        [$response, $trace] = $this->runAction(['beginFailure' => true]);
        self::assertTrue($response['error']);
        self::assertSame(['lock', 'begin', 'unlock'], $trace);
    }

    public function testCommitFailureAndLockException(): void
    {
        [$response, $trace] = $this->runAction(['commitFailure' => true]);
        self::assertTrue($response['error']);
        self::assertSame('', $response['email']);
        self::assertSame(['lock', 'begin', 'load', 'customer-save', 'save', 'commit', 'rollback', 'unlock'], $trace);
        [$response, $trace] = $this->runAction(['lockFailure' => true]);
        self::assertTrue($response['error']);
        self::assertSame(['lock'], $trace);
    }

    /**
     * @param mixed[] $options
     * @return mixed[]
     * @phpstan-return array{array{error: bool, message: string, email: string, ajaxExpired: bool}, list<string>, Order}
     */
    private function runAction(array $options): array
    {
        $trace = [];
        $level = 0;
        $request = $this->createMock(Http::class);
        $post = [
            'order_id' => 42, 'email' => !empty($options['same']) ? 'old@example.com' : 'new@example.com',
            'old_email' => 'old@example.com', 'create_new_customer' => $options['create'] ?? false,
            'assign_to_another_customer' => $options['assign'] ?? false,
        ];
        $request->method('getPost')->willReturnCallback(static fn ($key) => $post[$key]);
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($events);
        $response = [];
        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(static function ($data) use (&$response, $result) {
            $response = $data;
            return $result;
        });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $store = $this->createConfiguredMock(StoreInterface::class, ['getWebsiteId' => 1]);
        $address = $this->getMockBuilder(Address::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $shippingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()->onlyMethods([])->getMock();
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getStore', 'getAddresses', 'addStatusHistoryComment'])->getMock();
        $order->setData([
            'entity_id' => 42,
            'customer_id' => $options['customerId'] ?? 7,
            'customer_email' => !empty($options['stale']) ? 'other@example.com' : 'old@example.com',
            'store_id' => 1,
            'unrelated' => 'unchanged',
        ]);
        $order->method('getStore')->willReturn($store);
        $order->method('getAddresses')->willReturn([$address, $shippingAddress]);
        $order->method('addStatusHistoryComment')->willReturnCallback(static function ($comment) use ($order) {
            self::assertSame('Order email address change from old@example.com to new@example.com by admin', $comment);
            return $order;
        });
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(static function () use (&$trace, $order) {
            $trace[] = 'load';
            return $order;
        });
        $repository->method('save')->willReturnCallback(static function ($order) use (
            &$trace,
            $options,
            $address,
            $shippingAddress
        ) {
            $trace[] = 'save';
            self::assertSame('new@example.com', $address->getEmail());
            self::assertSame('new@example.com', $shippingAddress->getEmail());
            if (!empty($options['saveFailure'])) {
                throw new RuntimeException('relation failure');
            }
            return $order;
        });
        $customer = $this->createConfiguredMock(CustomerInterface::class, [
            'getId' => 99, 'getGroupId' => 3, 'getEmail' => 'new@example.com',
        ]);
        $provider = $this->createMock(CustomerProvider::class);
        $provider->method('getCustomerByEmail')->willReturn(!empty($options['existing']) ? $customer : null);
        $creator = $this->createMock(NewCustomerCreator::class);
        $creator->method('create')->willReturnCallback(static function () use (&$trace, $customer, $order, $options) {
            $trace[] = 'create';
            self::assertSame(0, $order->getCustomerId());
            if (isset($options['creationFailure'])) {
                throw $options['creationFailure'];
            }
            return $customer;
        });
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($customer);
        $customerRepository->method('save')->willReturnCallback(static function ($customer) use (
            &$trace,
            &$level,
            $options
        ) {
            $trace[] = 'customer-save';
            if (!empty($options['nested'])) {
                ++$level;
            }
            if (isset($options['failure'])) {
                throw $options['failure'];
            }
            return $customer;
        });
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(empty($options['invalid']));
        $user = $this->createConfiguredMock(User::class, ['getUserName' => 'admin']);
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()->addMethods(['getUser'])->getMock();
        $session->method('getUser')->willReturn($user);
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturnCallback(static function ($name, $timeout) use (&$trace, $options) {
            self::assertSame('order_update_42', $name);
            self::assertSame(30, $timeout);
            $trace[] = 'lock';
            if (!empty($options['lockFailure'])) {
                throw new RuntimeException('Lock failure');
            }
            return empty($options['timeout']);
        });
        $lock->method('unlock')->willReturnCallback(static function () use (&$trace, $options) {
            $trace[] = 'unlock';
            if (!empty($options['unlockFailure'])) {
                throw new RuntimeException();
            }
            return true;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('getTransactionLevel')->willReturnCallback(static function () use (&$level) {
            return $level;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (
            &$trace,
            &$level,
            $connection,
            $options
        ) {
            $trace[] = 'begin';
            if (!empty($options['beginFailure'])) {
                throw new RuntimeException();
            }
            ++$level;
            return $connection;
        });
        $connection->method('commit')->willReturnCallback(static function () use (
            &$trace,
            &$level,
            $connection,
            $options
        ) {
            $trace[] = 'commit';
            if (!empty($options['commitFailure'])) {
                throw new RuntimeException('Commit failure');
            }
            --$level;
            return $connection;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$trace, &$level, $connection) {
            $trace[] = 'rollback';
            --$level;
            return $connection;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $controller = new Index(
            $context,
            $repository,
            $factory,
            $customerRepository,
            $validator,
            $session,
            $provider,
            $creator,
            $lock,
            $resource,
            new Escaper(),
            $this->createMock(LoggerInterface::class)
        );
        $controller->execute();
        return [$response, $trace, $order];
    }
}
