<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Test\Unit\Controller\Adminhtml\Edit;

use Budsies\Sales\Service\BindCustomerWithOrders;
use Budsies\Sales\Service\CustomerProvider;
use MagePal\EditOrderEmail\Controller\Adminhtml\Edit\Index;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    public function testExecuteSavesUpdatedOrderWithoutDispatchingEmailChangeEvent(): void
    {
        $oldEmail = 'old@example.com';
        $newEmail = 'new@example.com';
        $request = $this->createMock(Http::class);
        $request->method('getPost')->willReturnCallback(static function (string $key) use ($oldEmail, $newEmail) {
            return [
                'order_id' => 42,
                'email' => $newEmail,
                'old_email' => $oldEmail,
                'create_new_customer' => false,
                'assign_to_another_customer' => true,
            ][$key];
        });

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->expects($this->never())->method('dispatch');

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEventManager')->willReturn($eventManager);

        $result = $this->createMock(Json::class);
        $result->expects($this->once())
            ->method('setData')
            ->with($this->callback(static function (array $data) use ($newEmail): bool {
                return $data['error'] === false
                    && $data['email'] === $newEmail
                    && $data['ajaxExpired'] === false;
            }))
            ->willReturnSelf();
        $resultFactory = $this->createMock(JsonFactory::class);
        $resultFactory->expects($this->once())->method('create')->willReturn($result);

        $store = $this->createConfiguredMock(StoreInterface::class, ['getWebsiteId' => 1]);
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getCustomerEmail')->willReturnOnConsecutiveCalls($oldEmail, $newEmail);
        $order->method('getStore')->willReturn($store);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getAddressesCollection')->willReturn([]);
        $order->expects($this->once())->method('addStatusHistoryComment');

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->once())->method('get')->with(42)->willReturn($order);
        $orderRepository->expects($this->once())->method('save')->with($order)->willReturn($order);

        $existingCustomer = $this->createMock(CustomerInterface::class);
        $customerProvider = $this->createMock(CustomerProvider::class);
        $customerProvider->expects($this->once())
            ->method('getCustomerByEmail')
            ->with($newEmail, 1)
            ->willReturn($existingCustomer);
        $bindCustomerWithOrders = $this->createMock(BindCustomerWithOrders::class);
        $bindCustomerWithOrders->expects($this->once())
            ->method('updateCustomerInOrder')
            ->with($order, $existingCustomer)
            ->willReturn($order);

        $emailAddressValidator = $this->createMock(EmailAddress::class);
        $emailAddressValidator->expects($this->once())->method('isValid')->with($newEmail)->willReturn(true);

        $user = $this->createConfiguredMock(User::class, ['getUserName' => 'admin']);
        $authSession = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUser'])
            ->getMock();
        $authSession->method('getUser')->willReturn($user);

        $controller = new Index(
            $context,
            $orderRepository,
            $this->createMock(AccountManagementInterface::class),
            $this->createMock(OrderCustomerManagementInterface::class),
            $resultFactory,
            $this->createMock(CustomerRepositoryInterface::class),
            $emailAddressValidator,
            $authSession,
            $customerProvider,
            $bindCustomerWithOrders,
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame($result, $controller->execute());
    }
}
