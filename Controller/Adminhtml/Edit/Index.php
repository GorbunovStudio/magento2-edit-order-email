<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Controller\Adminhtml\Edit;

use Budsies\General\Interface\LockNamePrefixInterface;
use Budsies\Sales\Service\CustomerProvider;
use Budsies\Sales\Service\NewCustomerCreator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Validation\ValidationException;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'MagePal_EditOrderEmail::magepal_editorderemail';
    private const LOCK_TIMEOUT = 30;

    public function __construct(
        Context $context,
        private OrderRepositoryInterface $orderRepository,
        private JsonFactory $resultJsonFactory,
        private CustomerRepositoryInterface $customerRepository,
        private EmailAddress $emailAddressValidator,
        private Session $authSession,
        private CustomerProvider $customerProvider,
        private NewCustomerCreator $newCustomerCreator,
        private LockManagerInterface $lockManager,
        private ResourceConnection $resourceConnection,
        private Escaper $escaper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $orderId = null;
        $locked = false;
        $lockName = '';
        $started = false;
        $connection = null;
        $initialLevel = 0;
        $response = [
            'error' => true,
            'message' => __('Unable to change the email address.'),
            'email' => '',
            'ajaxExpired' => false,
        ];

        try {
            $request = $this->getRequest();
            if (!$request instanceof Http) {
                throw new RuntimeException('Unexpected request type.');
            }
            $orderId = $request->getPost('order_id');
            $email = $request->getPost('email');
            $oldEmail = $request->getPost('old_email');
            if (!is_scalar($orderId) || !ctype_digit((string)$orderId) || (int)$orderId <= 0) {
                throw new ValidationException(__('Invalid order id.'));
            }
            $orderId = (int)$orderId;
            if (!is_string($email) || !$this->emailAddressValidator->isValid(trim($email))) {
                throw new ValidationException(__('Invalid Email address.'));
            }
            $email = trim($email);
            if (!is_string($oldEmail)) {
                throw new ValidationException(__('Order not found or email mismatch.'));
            }
            $lockName = LockNamePrefixInterface::ORDER_UPDATE . $orderId;
            $locked = $this->lockManager->lock($lockName, self::LOCK_TIMEOUT);
            if (!$locked) {
                $response['message'] = __('Unable to change the email address right now. Please try again.');
            } else {
                $connection = $this->resourceConnection->getConnection();
                $initialLevel = $connection->getTransactionLevel();
                $connection->beginTransaction();
                $started = true;
                $order = $this->orderRepository->get($orderId);
                if (!$order instanceof Order) {
                    throw new RuntimeException('Unexpected order type.');
                }
                if (!$order->getEntityId() || $order->getCustomerEmail() != $oldEmail) {
                    throw new ValidationException(__('Order not found or email mismatch.'));
                }
                if ($email == $oldEmail) {
                    throw new ValidationException(__('Email address is the same as the old one.'));
                }

                $websiteId = (int)$order->getStore()->getWebsiteId();
                $customer = $this->customerProvider->getCustomerByEmail($email, $websiteId);
                if ($customer) {
                    if (!$request->getPost('assign_to_another_customer')) {
                        throw new ValidationException(__(
                            'Customer with this email already exists. Please set the checkbox if you want to ' .
                            're-assign the order to that customer.'
                        ));
                    }
                    $this->assignCustomer($order, $customer);
                } elseif ($order->getCustomerId() && !$request->getPost('create_new_customer')) {
                    $customer = $this->customerRepository->getById($order->getCustomerId());
                    $customer->setEmail($email);
                    $this->customerRepository->save($customer);
                    $order->setCustomerEmail($email);
                } else {
                    $order->setCustomerId(0);
                    $order->setCustomerEmail($email);
                    $customer = $this->newCustomerCreator->create($orderId, (int)$order->getStoreId(), $email);
                    $this->assignCustomer($order, $customer);
                }

                $effectiveEmail = $order->getCustomerEmail();
                foreach ($order->getAddresses() as $address) {
                    $address->setEmail($effectiveEmail);
                }
                $comment = sprintf(
                    (string)__('Order email address change from %s to %s by %s'),
                    $oldEmail,
                    $email,
                    $this->authSession->getUser()->getUserName()
                );
                $order->addStatusHistoryComment($comment);
                $this->orderRepository->save($order);
                $connection->commit();
                $started = false;
                $response = [
                    'error' => false,
                    'message' => __('Email address successfully changed.'),
                    'email' => $effectiveEmail,
                    'ajaxExpired' => false,
                ];
            }
        } catch (Throwable $e) {
            if ($started && $connection !== null) {
                try {
                    while ($connection->getTransactionLevel() > $initialLevel) {
                        $connection->rollBack();
                    }
                } catch (Throwable $cleanupError) {
                    $this->logger->error('Edit order email rollback failed.', [
                        'order_id' => $orderId, 'type' => get_class($cleanupError),
                    ]);
                }
            }
            if (($e instanceof InputException || $e instanceof ValidationException
                || $e instanceof InputMismatchException || $e instanceof AlreadyExistsException) && !$e->getPrevious()
            ) {
                $messages = [];
                if ($e instanceof InputException || $e instanceof ValidationException) {
                    foreach ($e->getErrors() as $error) {
                        $messages[] = $error->getMessage();
                    }
                }
                $message = $messages ? implode(' ', $messages) : $e->getMessage();
                $response['message'] = $this->escaper->escapeHtml($message);
            } else {
                $this->logger->error('Edit order email failed.', ['order_id' => $orderId, 'type' => get_class($e)]);
            }
        } finally {
            if ($locked) {
                try {
                    $this->lockManager->unlock($lockName);
                } catch (Throwable $cleanupError) {
                    $this->logger->error('Edit order email unlock failed.', [
                        'order_id' => $orderId, 'type' => get_class($cleanupError),
                    ]);
                }
            }
        }

        return $result->setData($response);
    }

    private function assignCustomer(OrderInterface $order, CustomerInterface $customer): void
    {
        $order->setCustomerEmail($customer->getEmail());
        $order->setCustomerId((int)$customer->getId());
        $order->setCustomerGroupId((int)$customer->getGroupId());
        $order->setCustomerIsGuest(0);
    }
}
