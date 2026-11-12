<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Test\Unit\Controller\Adminhtml\Edit;

use Budsies\Sales\Service\AdminEmailEditContext;
use Budsies\Sales\Service\AdminEmailEditPlan;
use Budsies\Sales\Service\AdminEmailEditor;
use Budsies\Sales\Service\CustomerProvider;
use Budsies\Sales\Service\NewCustomerCreator;
use Budsies\Sales\Plugin\Order\Email\Sender\OrderSenderLockPlugin;
use MagePal\EditOrderEmail\Controller\Adminhtml\Edit\Index;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\Auth;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\App\ViewInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Container\OrderIdentity;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\User\Model\User;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../../../Controller/Adminhtml/Edit/Index.php';

class IndexTest extends TestCase
{
    /** @dataProvider senderOrders */
    public function testActualSenderAndEditorPreserveFlagsAcrossBothOrders(int $senderId, bool $senderFirst): void
    {
        $stored = [1 => ['send_email' => 0, 'email_sent' => 1], 42 => ['send_email' => 0, 'email_sent' => 1]];
        $identity = $this->createMock(OrderIdentity::class);
        $identity->method('isEnabled')->willReturn(true);
        $global = $this->createMock(ScopeConfigInterface::class);
        $global->method('getValue')->willReturn(1);
        $orderResource = $this->createMock(OrderResource::class);
        $orderResource->method('saveAttribute')->willReturnCallback(static function ($order, $fields) use (&$stored) {
            foreach ((array)$fields as $field) {
                $stored[$order->getId()][$field] = $order->getData($field);
            }
            return $order;
        });
        $reflection = new \ReflectionClass(OrderSender::class);
        $sender = $reflection->newInstanceWithoutConstructor();
        foreach (['identityContainer' => $identity, 'orderResource' => $orderResource, 'globalConfig' => $global] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($sender, $value);
        }
        $senderLocks = $this->createMock(LockManagerInterface::class);
        $senderLocks->method('lock')->willReturn(true);
        $senderLocks->method('unlock')->willReturn(true);
        $senderPlugin = new OrderSenderLockPlugin($senderLocks);
        $send = function () use ($sender, $senderPlugin, $senderId, &$stored) {
            $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getStore'])->getMock();
            $order->method('getStore')->willReturn($this->createMock(Store::class));
            $order->setIdFieldName('entity_id');
            $order->setData(array_merge(['entity_id' => $senderId], $stored[$senderId]));
            $senderPlugin->aroundSend($sender, static fn ($order, $force) => $sender->send($order, $force), $order);
        };
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 42, 'email' => 'new@example.test', 'old_email' => 'old@example.test',
            'create_new_customer' => false, 'assign_to_another_customer' => false,
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('setData')->with(self::callback(
            static fn (array $data): bool => $data['error'] === false
        ))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(true);
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->method('plan')->willReturn(['customer_id' => 7, 'account_email' => 'old@example.test',
            'order_email' => 'old@example.test', 'website_id' => 1, 'cascade' => true, 'order_ids' => [1, 42]]);
        $locks = $this->createMock(LockManagerInterface::class);
        $didSend = false;
        $locks->method('lock')->willReturnCallback(static function () use ($senderFirst, $send, &$didSend) {
            if ($senderFirst && !$didSend) {
                $didSend = true;
                $send();
            }
            return true;
        });
        $locks->method('unlock')->willReturnCallback(static function ($name) use ($senderFirst, $senderId, $send, &$didSend) {
            if (!$senderFirst && !$didSend && $name === 'order_update_' . $senderId) {
                $didSend = true;
                $send();
            }
            return true;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $orders = $this->createMock(OrderRepository::class);
        $orders->expects(self::once())->method('_resetState');
        $orders->method('get')->willReturnCallback(function () use (&$stored) {
            $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
                ->onlyMethods(['getAddresses', 'addStatusHistoryComment'])->getMock();
            $order->setData(array_merge(['entity_id' => 42, 'customer_email' => 'old@example.test'], $stored[42]));
            $order->method('getAddresses')->willReturn([]);
            return $order;
        });
        $orders->method('save')->willReturnCallback(static function ($order) use (&$stored) {
            $stored[42] = ['send_email' => $order->getSendEmail(), 'email_sent' => $order->getEmailSent()];
            return $order;
        });
        $editor = $this->createMock(AdminEmailEditor::class);
        $editor->method('apply')->willReturnCallback(static function ($order) use (&$stored) {
            $cascade = $stored[1];
            $stored[1] = $cascade;
            $order->setCustomerEmail('new@example.test');
            return false;
        });
        $user = $this->createConfiguredMock(User::class, ['getUserName' => 'admin']);
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()->addMethods(['getUser'])->getMock();
        $session->method('getUser')->willReturn($user);
        $controller = new Index($context, $orders, $factory, $validator, $session, $planner,
            new AdminEmailEditContext(), $editor, $resource, $locks, $this->createMock(CustomerRegistry::class),
            $this->createMock(LoggerInterface::class));
        $controller->execute();
        self::assertTrue($didSend);
        self::assertEquals(1, $stored[$senderId]['send_email']);
        self::assertNull($stored[$senderId]['email_sent']);
    }

    public static function senderOrders(): array
    {
        return [[1, true], [1, false], [42, true], [42, false]];
    }

    public function testDispatchRejectsAdministratorWithoutEditorAcl(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('isDispatched')->willReturn(true);
        $request->method('getActionName')->willReturn('index');
        $request->expects(self::once())->method('setDispatched')->with(true);
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::once())->method('setStatusHeader')->with(403, '1.1', 'Forbidden');
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::once())->method('isAllowed')
            ->with('MagePal_EditOrderEmail::magepal_editorderemail')->willReturn(false);
        $auth = $this->createMock(Auth::class);
        $auth->method('isLoggedIn')->willReturn(true);
        $view = $this->createMock(ViewInterface::class);
        $view->expects(self::once())->method('loadLayout');
        $view->expects(self::once())->method('renderLayout');
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResponse')->willReturn($response);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getAuth')->willReturn($auth);
        $context->method('getView')->willReturn($view);
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->expects(self::never())->method('plan');
        $controller = new Index($context, $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(JsonFactory::class), $this->createMock(EmailAddress::class),
            $this->createMock(Session::class), $planner, new AdminEmailEditContext(),
            $this->createMock(AdminEmailEditor::class), $this->createMock(ResourceConnection::class),
            $this->createMock(LockManagerInterface::class), $this->createMock(CustomerRegistry::class),
            $this->createMock(LoggerInterface::class));
        self::assertSame($response, $controller->dispatch($request));
    }

    /** @dataProvider workflowCases */
    public function testRealEditorWorkflowThroughController(string $kind, bool $guest, bool $assign, bool $create): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 42, 'email' => 'new@example.test', 'old_email' => 'old@example.test',
            'create_new_customer' => $create, 'assign_to_another_customer' => $assign,
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $result = $this->createMock(Json::class);
        $rejected = $kind === 'existing' && !$assign;
        $responses = [];
        $result->expects($rejected ? self::once() : self::exactly(2))->method('setData')
            ->willReturnCallback(static function (array $data) use (&$responses, $result) {
                $responses[] = $data;
                return $result;
            });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(true);
        $priorId = $guest ? null : 7;
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->method('plan')->willReturn(['customer_id' => $priorId, 'account_email' => $guest ? null : 'old@example.test',
            'order_email' => 'old@example.test', 'website_id' => 1, 'cascade' => $kind === 'update', 'order_ids' => [42]]);
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getAddresses', 'addStatusHistoryComment'])->getMock();
        $order->setData(['entity_id' => 42, 'store_id' => 1, 'customer_id' => $priorId,
            'customer_email' => 'old@example.test', 'customer_is_guest' => (int)$guest]);
        $address = $this->createMock(Address::class);
        $address->expects($rejected ? self::never() : self::once())->method('setEmail')->with('new@example.test');
        $order->method('getAddresses')->willReturn([$address]);
        $order->expects($rejected ? self::never() : self::once())->method('addStatusHistoryComment');
        $orders = $this->createMock(OrderRepository::class);
        $orders->method('get')->willReturn($order);
        $orders->expects($rejected ? self::never() : self::once())->method('save')->willReturn($order);
        $target = $this->createConfiguredMock(CustomerInterface::class, [
            'getId' => 8, 'getGroupId' => 2, 'getEmail' => 'new@example.test',
        ]);
        $provider = $this->createMock(CustomerProvider::class);
        $provider->method('getCustomerByEmail')->with('new@example.test', 1)
            ->willReturn($kind === 'existing' ? $target : null);
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        if ($kind === 'update') {
            $customer = $this->createMock(CustomerInterface::class);
            $customer->expects(self::once())->method('setEmail')->with('new@example.test');
            $customers->method('getById')->with(7)->willReturn($customer);
            $customers->expects(self::once())->method('save')->with($customer)->willReturn($customer);
        } else {
            $customers->expects(self::never())->method('save');
        }
        $creator = $this->createMock(NewCustomerCreator::class);
        $creator->expects($kind === 'create' ? self::once() : self::never())->method('create')->willReturn($target);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects($rejected ? self::never() : self::once())->method('commit');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->method('unlock')->willReturn(true);
        $user = $this->createConfiguredMock(User::class, ['getUserName' => 'admin']);
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()->addMethods(['getUser'])->getMock();
        $session->method('getUser')->willReturn($user);
        $editor = new AdminEmailEditor($provider, $customers, $creator);
        $controller = new Index($context, $orders, $factory, $validator, $session, $planner,
            new AdminEmailEditContext(), $editor, $resource, $locks, $this->createMock(CustomerRegistry::class),
            $this->createMock(LoggerInterface::class));
        self::assertSame($result, $controller->execute());
        self::assertSame($rejected, $responses[0]['error']);
        self::assertFalse($responses[0]['ajaxExpired']);
        if (!$rejected) {
            self::assertSame('new@example.test', $responses[0]['email']);
            self::assertSame('new@example.test', $order->getCustomerEmail());
            self::assertSame($kind === 'update' ? 7 : 8, $order->getCustomerId());
            self::assertFalse((bool)$order->getCustomerIsGuest());
            self::assertSame($result, $controller->execute());
            self::assertTrue($responses[1]['error']);
        }
    }

    public static function workflowCases(): array
    {
        return [
            ['existing', false, true, false], ['existing', true, true, false],
            ['existing', false, false, false], ['existing', true, false, false],
            ['create', false, false, true], ['create', true, false, false], ['update', false, false, false],
        ];
    }

    /** @dataProvider failureCases */
    public function testTransactionOutcomes(string $case): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 42, 'email' => 'new@example.test', 'old_email' => 'old@example.test',
            'create_new_customer' => false, 'assign_to_another_customer' => true,
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(self::callback(static function (array $data) use ($case) {
            if ($case === 'unlock') {
                return !$data['error'] && $data['email'] === 'new@example.test';
            }
            if (!$data['error'] || str_contains((string)$data['message'], 'sensitive-detail')) {
                return false;
            }
            return $case !== 'commit' || str_contains((string)$data['message'], 'could not be confirmed');
        }))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(true);
        $plan = ['customer_id' => 7, 'account_email' => 'old@example.test', 'order_email' => 'old@example.test',
            'website_id' => 1, 'cascade' => true, 'order_ids' => [1, 42]];
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->method('plan')->willReturn($plan);
        $names = [];
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(static function ($name) use (&$names) {
            $names[] = $name;
            return true;
        });
        $locks->expects(self::exactly(2))->method('unlock')->willReturnCallback(static function () use ($case) {
            if ($case === 'unlock') {
                throw new \RuntimeException('sensitive-detail');
            }
            return true;
        });
        $connection = $this->createMock(AdapterInterface::class);
        if ($case === 'commit') {
            $connection->method('commit')->willThrowException(new \RuntimeException('sensitive-detail'));
        }
        $rollback = $connection->expects($case === 'unlock' ? self::never() : self::once())->method('rollBack');
        if ($case === 'rollback') {
            $rollback->willThrowException(new \RuntimeException('sensitive-detail'));
        }
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $order = $this->createMock(Order::class);
        $order->method('getCustomerEmail')->willReturnOnConsecutiveCalls(
            $case === 'stale' ? 'other@example.test' : 'old@example.test',
            $case === 'same' ? 'new@example.test' : 'old@example.test',
            'new@example.test', 'new@example.test'
        );
        $order->method('getAddresses')->willReturn([]);
        $orders = $this->createMock(OrderRepository::class);
        if ($case === 'missing') {
            $orders->method('get')->willThrowException(new NoSuchEntityException(__('Not found')));
        } else {
            $orders->method('get')->willReturn($order);
        }
        if ($case === 'save' || $case === 'rollback') {
            $orders->method('save')->willThrowException(new \RuntimeException('sensitive-detail'));
        }
        $editor = $this->createMock(AdminEmailEditor::class);
        $editor->expects(in_array($case, ['stale', 'same', 'missing'], true) ? self::never() : self::once())
            ->method('apply')->willReturn(true);
        $user = $this->createConfiguredMock(User::class, ['getUserName' => 'admin']);
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()->addMethods(['getUser'])->getMock();
        $session->method('getUser')->willReturn($user);
        $editContext = new AdminEmailEditContext();
        $controller = new Index($context, $orders, $factory, $validator, $session, $planner, $editContext,
            $editor, $resource, $locks, $this->createMock(CustomerRegistry::class), $this->createMock(LoggerInterface::class));
        self::assertSame($result, $controller->execute());
        self::assertSame(['order_update_1', 'order_update_42'], $names);
        self::assertFalse($editContext->isActive());
    }

    public static function failureCases(): array
    {
        return [['stale'], ['same'], ['save'], ['commit'], ['unlock'], ['missing'], ['rollback']];
    }

    public function testFailureAfterActivationRollsBackAndClearsContext(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 42, 'email' => 'new@example.test', 'old_email' => 'old@example.test',
            'create_new_customer' => false, 'assign_to_another_customer' => false,
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(self::callback(
            static fn (array $data): bool => $data['error'] && !str_contains((string)$data['message'], 'secret')
        ))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(true);
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->method('plan')->willReturn(['customer_id' => 7, 'account_email' => 'old@example.test',
            'order_email' => 'old@example.test', 'website_id' => 1, 'cascade' => false, 'order_ids' => [42]]);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->expects(self::once())->method('unlock')->willReturn(true);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $orders = $this->createMock(OrderRepository::class);
        $orders->method('get')->willThrowException(new \RuntimeException('secret persistence diagnostic'));
        $editorContext = new AdminEmailEditContext();
        $controller = new Index($context, $orders, $factory, $validator, $this->createMock(Session::class),
            $planner, $editorContext, $this->createMock(AdminEmailEditor::class), $resource, $locks,
            $this->createMock(CustomerRegistry::class), $this->createMock(LoggerInterface::class));
        self::assertSame($result, $controller->execute());
        self::assertFalse($editorContext->isActive());
    }

    public function testPartialLockFailureReleasesOnlyAcquiredLocksWithoutStartingTransaction(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 42, 'email' => 'new@example.test', 'old_email' => 'old@example.test',
            'create_new_customer' => false, 'assign_to_another_customer' => false,
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(self::callback(
            static fn (array $data): bool => $data['error'] && str_contains((string)$data['message'], 'busy')
        ))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(true);
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->method('plan')->willReturn(['customer_id' => 7, 'account_email' => 'old@example.test',
            'order_email' => 'old@example.test', 'website_id' => 1, 'cascade' => true, 'order_ids' => [1, 42]]);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects(self::exactly(2))->method('lock')->willReturnOnConsecutiveCalls(true, false);
        $locks->expects(self::once())->method('unlock')->with('order_update_1')->willReturn(true);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $editorContext = new AdminEmailEditContext();
        $controller = new Index($context, $this->createMock(OrderRepositoryInterface::class), $factory, $validator,
            $this->createMock(Session::class), $planner, $editorContext, $this->createMock(AdminEmailEditor::class),
            $resource, $locks, $this->createMock(CustomerRegistry::class), $this->createMock(LoggerInterface::class));
        self::assertSame($result, $controller->execute());
        self::assertFalse($editorContext->isActive());
    }

    public function testEditLocksBeforeTransactionAndSavesWithoutControllerDispatch(): void
    {
        $steps = [];
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 42, 'email' => 'new@example.test', 'old_email' => 'old@example.test',
            'create_new_customer' => false, 'assign_to_another_customer' => true,
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(self::callback(
            static fn (array $data): bool => !$data['error'] && $data['email'] === 'new@example.test'
        ))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $validator = $this->createMock(EmailAddress::class);
        $validator->method('isValid')->willReturn(true);
        $plan = ['customer_id' => 7, 'account_email' => 'old@example.test', 'order_email' => 'old@example.test',
            'website_id' => 1, 'cascade' => false, 'order_ids' => [42]];
        $planner = $this->createMock(AdminEmailEditPlan::class);
        $planner->method('plan')->willReturn($plan);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(static function () use (&$steps) {
            $steps[] = 'lock';
            return true;
        });
        $locks->method('unlock')->willReturnCallback(static function () use (&$steps) {
            $steps[] = 'unlock';
            return true;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$steps) {
            $steps[] = 'begin';
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$steps) {
            $steps[] = 'commit';
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $order = $this->createMock(Order::class);
        $order->method('getCustomerEmail')->willReturnOnConsecutiveCalls(
            'old@example.test', 'old@example.test', 'new@example.test', 'new@example.test', 'new@example.test'
        );
        $address = $this->createMock(Address::class);
        $address->expects(self::once())->method('setEmail')->with('new@example.test');
        $order->method('getAddresses')->willReturn([$address]);
        $order->expects(self::once())->method('addStatusHistoryComment')->with(self::callback(
            static fn ($comment): bool => str_contains((string)$comment, 'old@example.test')
                && str_contains((string)$comment, 'new@example.test') && str_contains((string)$comment, 'admin')
        ));
        $order->expects(self::never())->method('setStatus');
        $orders = $this->createMock(OrderRepository::class);
        $orders->method('get')->willReturnCallback(static function () use (&$steps, $order) {
            $steps[] = 'load';
            return $order;
        });
        $orders->expects(self::once())->method('save')->with($order)->willReturn($order);
        $editor = $this->createMock(AdminEmailEditor::class);
        $editor->expects(self::once())->method('apply')->willReturn(true);
        $user = $this->createConfiguredMock(User::class, ['getUserName' => 'admin']);
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()->addMethods(['getUser'])->getMock();
        $session->method('getUser')->willReturn($user);
        $controller = new Index($context, $orders, $factory, $validator, $session, $planner,
            new AdminEmailEditContext(), $editor, $resource, $locks, $this->createMock(CustomerRegistry::class),
            $this->createMock(LoggerInterface::class));
        self::assertSame($result, $controller->execute());
        self::assertSame(['lock', 'begin', 'load', 'commit', 'unlock'], $steps);
    }

    public function testInvalidInputDoesNotAcquireALock(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static fn ($key) => [
            'order_id' => 1,
            'email' => ['invalid'],
            'old_email' => 'old@example.test',
        ][$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(self::callback(
            static fn (array $data): bool => $data['error'] && $data['email'] === '' && !$data['ajaxExpired']
                && str_contains((string)$data['message'], 'Invalid order or email input')
        ))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects(self::never())->method('lock');
        $controller = new Index(
            $context,
            $this->createMock(OrderRepositoryInterface::class),
            $factory,
            $this->createMock(EmailAddress::class),
            $this->createMock(Session::class),
            $this->createMock(AdminEmailEditPlan::class),
            new AdminEmailEditContext(),
            $this->createMock(AdminEmailEditor::class),
            $this->createMock(ResourceConnection::class),
            $locks,
            $this->createMock(CustomerRegistry::class),
            $this->createMock(LoggerInterface::class),
        );
        self::assertSame($result, $controller->execute());
    }
}
