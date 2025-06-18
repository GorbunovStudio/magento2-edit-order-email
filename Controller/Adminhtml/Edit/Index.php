<?php
/**
 * Copyright © MagePal LLC. All rights reserved.
 * See COPYING.txt for license details.
 * http://www.magepal.com | support@magepal.com
*/

namespace MagePal\EditOrderEmail\Controller\Adminhtml\Edit;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Budsies\Sales\Service\CustomerProvider;
use Budsies\Sales\Service\NewCustomerCreator;
use MagePal\EditOrderEmail\Service\UpdateCustomerInOrder;

class Index extends Action
{

    const ADMIN_RESOURCE = 'MagePal_EditOrderEmail::magepal_editorderemail';
    
    public function __construct(
        private Context $context,
        private OrderRepositoryInterface $orderRepository,
        private AccountManagementInterface $accountManagement,
        private OrderCustomerManagementInterface $orderCustomerService,
        private JsonFactory $resultJsonFactory,
        private CustomerRepositoryInterface $customerRepository,
        private EmailAddress $emailAddressValidator,
        private Session $authSession,
        private EventManager $eventManager,
        private CustomerProvider $customerProvider,
        private NewCustomerCreator $newCustomerCreator,
        private UpdateCustomerInOrder $updateCustomerInOrder,
    ) {
    }

    /**
     * Index action
     * @return Json
     * @throws Exception
     */
    public function execute()
    {
        $request = $this->getRequest();
        $orderId = $request->getPost('order_id');
        $emailAddress = trim($request->getPost('email'));
        $oldEmailAddress = $request->getPost('old_email');
        $createNewCustomerRecord = $request->getPost('create_new_customer');
        $assignToAnotherCustomerRecord = $request->getPost('assign_to_another_customer');
        $resultJson = $this->resultJsonFactory->create();

        if (!isset($orderId)) {
            return $resultJson->setData([
                'error' => true,
                'message' => __('Invalid order id.'),
                'email' => '',
                'ajaxExpired' => false
            ]);
        }
        if (!$this->emailAddressValidator->isValid($emailAddress)) {
            return $resultJson->setData([
                'error' => true,
                'message' => __('Invalid Email address.'),
                'email' => '',
                'ajaxExpired' => false
            ]);
        }
        try {
            $order = $this->orderRepository->get($orderId);

            if (!$order->getEntityId() || $order->getCustomerEmail() != $oldEmailAddress) {
                throw new \Exception(__('Order not found or email mismatch.'));
            }

            if ($emailAddress == $oldEmailAddress) {
                return $resultJson->setData([
                    'error' => true,
                    'message' => __('Email address is the same as the old one.'),
                    'email' => $emailAddress,
                    'ajaxExpired' => false
                ]);
            }

            $websiteId = $order->getStore()->getWebsiteId();
            $customer = $this->customerProvider->getCustomerByEmail($emailAddress, $websiteId);

            if ($customer) {
                if ($assignToAnotherCustomerRecord == 1) {
                    $order = $this->updateCustomerInOrder->update($order, $customer);
                } else {
                    return $resultJson->setData([
                        'error' => true,
                        'message' => __('Customer with this email already exists. Please check the checkbox to assign.'),
                        'email' => '',
                        'ajaxExpired' => false
                    ]);
                }
            } else {
                if ($createNewCustomerRecord == 1 && $order->getCustomerId()) {
                    $order->setCustomerId(null);
                    $order->setCustomerEmail($emailAddress);

                    $newCustomer = $this->newCustomerCreator->create($order->getEntityId(), $order->getStoreId(), $emailAddress);
                    $order = $this->updateCustomerInOrder->update($order, $newCustomer);
                } else {
                    $order->setCustomerEmail($emailAddress);

                    $customerFromOrder = $this->customerProvider->getCustomerByEmail($oldEmailAddress, $websiteId);
                    $customerFromOrder->setEmail($emailAddress);
                    $this->customerRepository->save($customerFromOrder);
                }
            }
            $comment = sprintf(
                __('Order email address change from %s to %s by %s'),
                $oldEmailAddress,
                $emailAddress,
                $this->authSession->getUser()->getUserName()
            );
            $order->addStatusHistoryComment($comment);
            $this->orderRepository->save($order);

            $email = $order->getCustomerEmail();
            foreach ($order->getAddressesCollection() as $address) {
                $address->setEmail($email)->save();
            }
            $this->eventManager->dispatch(
                'budsies_sales_order_customer_email_change',
                [
                    'order'              => $order,
                    'new_customer_email' => $email,
                    'old_customer_email' => $oldEmailAddress,
                ]
            );
            return $resultJson->setData([
                'error' => false,
                'message' => __('Email address successfully changed.'),
                'email' => $email,
                'ajaxExpired' => false
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'error' => true,
                'message' => $e->getMessage(),
                'email' => '',
                'ajaxExpired' => false
            ]);
        }
    }
}
