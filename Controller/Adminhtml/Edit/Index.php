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
use Budsies\Sales\Service\BindCustomerWithOrders;

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
     * @var EventManager
     */
    private EventManager $eventManager;
    /**
     * @var CustomerProvider
     */
    private CustomerProvider $customerProvider;
    /**
     * @var NewCustomerCreator
     */
    private NewCustomerCreator $newCustomerCreator;
    /**
     * @var UpdateCustomerInOrder
     */
    private BindCustomerWithOrders $bindCustomerWithOrders;

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
     * @param EventManager $eventManager
     * @param CreateNewCustomerIfUserIsGuest $createNewCustomerIfUserIsGuest
     * @param CustomerProvider $customerProvider
     * @param NewCustomerCreator $newCustomerCreator
     * @param BindCustomerWithOrders $bindCustomerWithOrders
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
        EventManager $eventManager,
        CustomerProvider $customerProvider,
        NewCustomerCreator $newCustomerCreator,
        BindCustomerWithOrders $bindCustomerWithOrders,
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->orderCustomerService = $orderCustomerService;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->accountManagement = $accountManagement;
        $this->customerRepository = $customerRepository;
        $this->emailAddressValidator = $emailAddressValidator;
        $this->authSession = $authSession;
        $this->eventManager = $eventManager;
        $this->customerProvider = $customerProvider;
        $this->newCustomerCreator = $newCustomerCreator;
        $this->bindCustomerWithOrders = $bindCustomerWithOrders;
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
                    $order = $this->bindCustomerWithOrders->updateCustomerInOrder($order, $customer);
                } else {
                    return $resultJson->setData([
                        'error' => true,
                        'message' => __('Customer with this email already exists. Please select the checkbox to assign.'),
                        'email' => '',
                        'ajaxExpired' => false
                    ]);
                }
            } else {
                if ($createNewCustomerRecord == 1 && $order->getCustomerId()) {
                    $order->setCustomerId(null);
                    $order->setCustomerEmail($emailAddress);

                    $newCustomer = $this->newCustomerCreator->create($order->getEntityId(), $order->getStoreId(), $emailAddress);
                    $order = $this->bindCustomerWithOrders->updateCustomerInOrder($order, $newCustomer);
                    
                } else {
                    $order->setCustomerEmail($emailAddress);

                    $customerFromOrder = $this->customerProvider->getCustomerByEmail($oldEmailAddress, $websiteId);
                    if (!$customerFromOrder) {
                        throw new \Exception(__('Customer with email address "'. $oldEmailAddress .'" not found.'));
                    }

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
