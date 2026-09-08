<?php
/**
 * Copyright © MagePal LLC. All rights reserved.
 * See COPYING.txt for license details.
 * https://www.magepal.com | support@magepal.com
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
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Budsies\Sales\Service\CustomerProvider;
use Budsies\Sales\Service\BindCustomerWithOrders;
use Psr\Log\LoggerInterface;


class Index extends Action
{

    const ADMIN_RESOURCE = 'MagePal_EditOrderEmail::magepal_editorderemail';
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var AccountManagementInterface
     */
    protected $accountManagement;

    /**
     * @var OrderCustomerManagementInterface
     */
    protected $orderCustomerService;

    /**
     * @var CustomerRepositoryInterface $customerRepository
     */
    protected $customerRepository;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;
    /**
     * @var EmailAddress
     */
    private $emailAddressValidator;
    /**
     * @var Session
     */
    private $authSession;
    /**
     * @var CustomerProvider
     */
    private CustomerProvider $customerProvider;

    /**
     * @var BindCustomerWithOrders
     */
    private BindCustomerWithOrders $bindCustomerWithOrders;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Index constructor.
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param AccountManagementInterface $accountManagement
     * @param OrderCustomerManagementInterface $orderCustomerService
     * @param JsonFactory $resultJsonFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param EmailAddress $emailAddressValidator
     * @param Session $authSession
     * @param CustomerProvider $customerProvider
     * @param BindCustomerWithOrders $bindCustomerWithOrders
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        AccountManagementInterface $accountManagement,
        OrderCustomerManagementInterface $orderCustomerService,
        JsonFactory $resultJsonFactory,
        CustomerRepositoryInterface $customerRepository,
        EmailAddress $emailAddressValidator,
        Session $authSession,
        CustomerProvider $customerProvider,
        BindCustomerWithOrders $bindCustomerWithOrders,
        LoggerInterface $logger,
    ) {
        parent::__construct($context);

        $this->orderRepository = $orderRepository;
        $this->orderCustomerService = $orderCustomerService;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->accountManagement = $accountManagement;
        $this->customerRepository = $customerRepository;
        $this->emailAddressValidator = $emailAddressValidator;
        $this->authSession = $authSession;
        $this->customerProvider = $customerProvider;
        $this->bindCustomerWithOrders = $bindCustomerWithOrders;
        $this->logger = $logger;
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

        $this->logger->info(sprintf(
            '35954 - MagePal - Action started. OrderId: %s, email: %s',
            (string)$orderId,
            (string)$emailAddress
        ));

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
            $customerForNewEmail = $this->customerProvider->getCustomerByEmail($emailAddress, $websiteId);
            $hasOrderCustomer = $order->getCustomerId() ? true : false;

            if ($customerForNewEmail) {
                    if ($assignToAnotherCustomerRecord) {
                        $order = $this->bindCustomerWithOrders->updateCustomerInOrder($order, $customerForNewEmail);
                    } else {
                        return $resultJson->setData([
                            'error' => true,
                            'message' => __('Customer with this email already exists. Please set the checkbox if you want to re-assign the order to that customer.'),
                            'email' => '',
                            'ajaxExpired' => false
                        ]);
                    }
            } else {
                if ($hasOrderCustomer && !$createNewCustomerRecord) {
                    $customerFromOrder = $this->customerRepository->getById($order->getCustomerId());
                    if (!$customerFromOrder) {
                        throw new \Exception(__('Customer with email address "'. $oldEmailAddress .'" not found.'));
                    }

                    $customerFromOrder->setEmail($emailAddress);
                    $this->customerRepository->save($customerFromOrder);

                    $order->setCustomerEmail($emailAddress);
                } else {
                    $order = $this->bindCustomerWithOrders->createNewCustomerAndBindWithOrder($order, $emailAddress);
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

            $this->logger->info(sprintf(
                '35954 - MagePal - Action finished. OrderId: %s, email: %s, old email: %s',
                (string)$order->getEntityId(),
                (string)$email,
                (string)$oldEmailAddress
            ));
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
