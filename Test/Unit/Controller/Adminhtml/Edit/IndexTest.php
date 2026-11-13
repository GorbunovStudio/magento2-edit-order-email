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
use Magento\Framework\DB\Select;
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
            self::assertSame(1, $order->getSendEmail());
            self::assertSame(1, $order->getEmailSent());
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

    public function testCaseVariantReassignmentReturnsAndPersistsCustomerEmail(): void
    {
        [$response, , $order] = $this->runAction([
            'existing' => true,
            'assign' => true,
            'email' => 'NEW@EXAMPLE.COM',
        ]);
        self::assertFalse($response['error']);
        self::assertSame('new@example.com', $order->getCustomerEmail());
        self::assertSame($order->getCustomerEmail(), $response['email']);
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
        foreach ([
            [new InputException(new Phrase('Name is required')), 'Name is required'],
            [new AlreadyExistsException(new Phrase('Email already exists')), 'Email already exists'],
        ] as [$exception, $message]) {
            [$response, $trace] = $this->runAction(['failure' => $exception]);
            self::assertTrue($response['error']);
            self::assertStringContainsString($message, $response['message']);
            self::assertSame('', $response['email']);
            self::assertFalse($response['ajaxExpired']);
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
        $aggregate = new InputException();
        $aggregate->addError(new Phrase('Name is required'));
        $aggregate->addError(new Phrase('Address <invalid>'));
        [$response, $trace] = $this->runAction(['create' => true, 'creationFailure' => $aggregate]);
        self::assertTrue($response['error']);
        self::assertStringContainsString('Name is required', $response['message']);
        self::assertStringContainsString('Address &lt;invalid&gt;', $response['message']);
        self::assertSame(['lock', 'begin', 'load', 'create', 'rollback', 'unlock'], $trace);
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

    public function testMultipleLocksAreOrderedAndReleasedAfterCommit(): void
    {
        [$response, $trace] = $this->runAction(['peers' => [42, 8, 3], 'traceLocks' => true]);
        self::assertFalse($response['error']);
        self::assertSame([
            'lock:3', 'lock:8', 'lock:42', 'begin', 'load', 'customer-save', 'save', 'commit',
            'unlock:42', 'unlock:8', 'unlock:3',
        ], $trace);
    }

    public function testPeerAcquisitionFailureAndCleanup(): void
    {
        foreach ([['timeoutAt' => 8], ['lockFailureAt' => 8], ['timeoutAt' => 42]] as $failure) {
            [$response, $trace] = $this->runAction($failure + ['peers' => [3, 8, 42], 'traceLocks' => true]);
            self::assertTrue($response['error']);
            self::assertNotContains('begin', $trace);
            self::assertNotContains('load', $trace);
            self::assertSame('unlock:3', end($trace));
        }
    }

    public function testChangedSelectedCustomerLinkRejectsOnlyExistingAccountUpdate(): void
    {
        [$response] = $this->runAction(['discoveredId' => '7']);
        self::assertFalse($response['error']);
        [$response, $trace] = $this->runAction(['discoveredId' => 0]);
        self::assertTrue($response['error']);
        self::assertStringContainsString('Order not found or email mismatch.', $response['message']);
        self::assertNotContains('customer-save', $trace);
        self::assertSame(['lock', 'begin', 'load', 'rollback', 'unlock'], $trace);
        [$response, $trace] = $this->runAction([
            'discoveredId' => 12, 'peers' => [3, 8], 'traceLocks' => true,
        ]);
        self::assertTrue($response['error']);
        self::assertSame([
            'lock:3', 'lock:8', 'lock:42', 'begin', 'load', 'rollback', 'unlock:42', 'unlock:8', 'unlock:3',
        ], $trace);
        foreach ([['existing' => true, 'assign' => true], ['create' => true]] as $choice) {
            [$response] = $this->runAction($choice + ['discoveredId' => 12]);
            self::assertFalse($response['error']);
        }
    }

    public function testUnlockFailureDoesNotPreventRemainingReleases(): void
    {
        [$response, $trace] = $this->runAction([
            'peers' => [3, 8, 42], 'traceLocks' => true, 'unlockFailureAt' => 8,
        ]);
        self::assertFalse($response['error']);
        self::assertSame(['unlock:42', 'unlock:8', 'unlock:3'], array_slice($trace, -3));
    }

    public function testAllLocksRemainHeldThroughNestedRollbackAndFailedBegin(): void
    {
        [$response, $trace] = $this->runAction([
            'peers' => [3, 8], 'traceLocks' => true, 'failure' => new Error('nested'), 'nested' => true,
        ]);
        self::assertTrue($response['error']);
        self::assertSame([
            'lock:3', 'lock:8', 'lock:42', 'begin', 'load', 'customer-save', 'rollback', 'rollback',
            'unlock:42', 'unlock:8', 'unlock:3',
        ], $trace);
        [$response, $trace] = $this->runAction(['peers' => [3, 8], 'traceLocks' => true, 'beginFailure' => true]);
        self::assertTrue($response['error']);
        self::assertSame(['lock:3', 'lock:8', 'lock:42', 'begin', 'unlock:42', 'unlock:8', 'unlock:3'], $trace);
    }

    public function testDiscoveryUsesAccountEmailAndLocksPeersBeforeReassignment(): void
    {
        [$response, $trace] = $this->runAction([
            'peers' => [3, 42], 'traceLocks' => true, 'existing' => true, 'assign' => true, 'traceDiscovery' => true,
        ]);
        self::assertFalse($response['error']);
        self::assertSame([
            'from:sales_order', 'where:entity_id = ?:42', 'from:customer_entity', 'where:entity_id = ?:7',
            'from:sales_order', 'where:customer_id = ?:7', 'where:customer_email = ?:account@example.com',
            'lock:3', 'lock:42', 'begin', 'load', 'target-lookup', 'save', 'commit', 'unlock:42', 'unlock:3',
        ], $trace);
        [$response, $trace] = $this->runAction([
            'peers' => [3, 42], 'traceLocks' => true, 'existing' => true,
        ]);
        self::assertTrue($response['error']);
        self::assertSame(['lock:3', 'lock:42', 'begin', 'load', 'rollback', 'unlock:42', 'unlock:3'], $trace);
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
            'order_id' => 42,
            'email' => $options['email'] ?? (!empty($options['same']) ? 'old@example.com' : 'new@example.com'),
            'old_email' => 'old@example.com',
            'create_new_customer' => $options['create'] ?? false,
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
            'send_email' => 1,
            'email_sent' => 1,
        ]);
        $order->method('getStore')->willReturn($store);
        $order->method('getAddresses')->willReturn([$address, $shippingAddress]);
        $order->method('addStatusHistoryComment')->willReturnCallback(static function ($comment) use ($order, $post) {
            self::assertSame(
                'Order email address change from ' . $post['old_email'] . ' to ' . $post['email'] . ' by admin',
                $comment
            );
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
            $shippingAddress,
            $post
        ) {
            $trace[] = 'save';
            $expectedEmail = !empty($options['existing']) ? 'new@example.com' : $post['email'];
            self::assertSame($expectedEmail, $address->getEmail());
            self::assertSame($expectedEmail, $shippingAddress->getEmail());
            if (!empty($options['saveFailure'])) {
                throw new RuntimeException('relation failure');
            }
            return $order;
        });
        $customer = $this->createConfiguredMock(CustomerInterface::class, [
            'getId' => 99, 'getGroupId' => 3, 'getEmail' => 'new@example.com',
        ]);
        $provider = $this->createMock(CustomerProvider::class);
        $provider->method('getCustomerByEmail')->willReturnCallback(static function () use (
            &$trace,
            $options,
            $customer
        ) {
            if (!empty($options['traceDiscovery'])) {
                $trace[] = 'target-lookup';
            }
            return !empty($options['existing']) ? $customer : null;
        });
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
            $id = (int)substr($name, strlen('order_update_'));
            self::assertSame(30, $timeout);
            $trace[] = !empty($options['traceLocks']) ? 'lock:' . $id : 'lock';
            if (!empty($options['lockFailure']) || ($options['lockFailureAt'] ?? null) === $id) {
                throw new RuntimeException('Lock failure');
            }
            return empty($options['timeout']) && ($options['timeoutAt'] ?? null) !== $id;
        });
        $lock->method('unlock')->willReturnCallback(static function ($name) use (&$trace, $options) {
            $id = (int)substr($name, strlen('order_update_'));
            $trace[] = !empty($options['traceLocks']) ? 'unlock:' . $id : 'unlock';
            if (!empty($options['unlockFailure']) || ($options['unlockFailureAt'] ?? null) === $id) {
                throw new RuntimeException();
            }
            return true;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
        $select->method('from')->willReturnCallback(static function ($name) use ($select, &$trace, $options) {
            if (!empty($options['traceDiscovery'])) {
                $trace[] = 'from:' . $name;
            }
            return $select;
        });
        $select->method('where')->willReturnCallback(static function (
            $condition,
            $value
        ) use (
            $select,
            &$trace,
            $options
        ) {
            if (!empty($options['traceDiscovery'])) {
                $trace[] = 'where:' . $condition . ':' . $value;
            }
            return $select;
        });
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            $options['discoveredId'] ?? ($options['customerId'] ?? 7),
            'account@example.com'
        );
        $connection->method('fetchCol')->willReturn($options['peers'] ?? []);
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
        $resource->method('getTableName')->willReturnCallback(static fn ($name) => $name);
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
