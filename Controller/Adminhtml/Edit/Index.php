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
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Budsies\Sales\Service\CreateNewCustomerIfUserIsGuest;
use Budsies\Sales\Service\CustomerProvider;
use Budsies\Sales\Service\NewCustomerCreator;

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
        NewCustomerCreator $newCustomerCreator
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

            $websiteId = $order->getStore()->getWebsiteId();
            $customer = $this->customerProvider->getCustomerByEmail($emailAddress, $websiteId);

            if ($customer) {
                if ($assignToAnotherCustomerRecord == 1) {
                    $order->setCustomerEmail($customer->getEmail());
                    $order->setCustomerId($customer->getId());
                    $order->setCustomerGroupId($customer->getGroupId());
                    $order->setCustomerIsGuest(0);
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
                    $newCustomer = $this->newCustomerCreator->create($order->getEntityId(), $order->getStoreId());
                    $order->setCustomerEmail($newCustomer->getEmail());
                    $order->setCustomerId($newCustomer->getId());
                    $order->setCustomerGroupId($newCustomer->getGroupId());
                    $order->setCustomerIsGuest(0);
                } else {
                    $order->setCustomerEmail($emailAddress);
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
