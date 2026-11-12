<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Test\Unit\Controller\Adminhtml\Edit;

use Budsies\Sales\Service\AdminEmailEditContext;
use Budsies\Sales\Service\AdminEmailEditPlan;
use Budsies\Sales\Service\AdminEmailEditor;
use MagePal\EditOrderEmail\Controller\Adminhtml\Edit\Index;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\OrderRepository;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../../../Controller/Adminhtml/Edit/Index.php';

class IndexTest extends TestCase
{
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
