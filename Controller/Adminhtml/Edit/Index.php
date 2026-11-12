<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Controller\Adminhtml\Edit;

use Budsies\General\Interface\LockNamePrefixInterface;
use Budsies\Sales\Service\AdminEmailEditContext;
use Budsies\Sales\Service\AdminEmailEditPlan;
use Budsies\Sales\Service\AdminEmailEditor;
use Budsies\Sales\Service\AdminEmailInputException;
use Budsies\Sales\Service\AdminEmailStaleEditException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Validator\EmailAddress;
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
        private OrderRepositoryInterface $orders,
        private JsonFactory $jsonFactory,
        private EmailAddress $validator,
        private Session $authSession,
        private AdminEmailEditPlan $planner,
        private AdminEmailEditContext $editContext,
        private AdminEmailEditor $editor,
        private ResourceConnection $resource,
        private LockManagerInterface $locks,
        private CustomerRegistry $customerRegistry,
        private LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $id = filter_var($request->getPost('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $email = $request->getPost('email');
        $oldEmail = $request->getPost('old_email');
        $result = ['error' => true, 'message' => __('Unable to edit the order email.'), 'email' => '', 'ajaxExpired' => false];
        $acquired = [];
        $started = false;
        $committing = false;
        $committed = false;

        try {
            if (!is_int($id) || !is_string($email) || !is_string($oldEmail)) {
                throw new AdminEmailInputException(__('Invalid order or email input.'));
            }
            $email = trim($email);
            if (!$this->validator->isValid($email)) {
                throw new AdminEmailInputException(__('Invalid Email address.'));
            }
            $create = (bool)$request->getPost('create_new_customer');
            $assign = (bool)$request->getPost('assign_to_another_customer');
            $plan = $this->planner->plan($id, $email, $create);
            foreach ($plan['order_ids'] as $orderId) {
                $lock = LockNamePrefixInterface::ORDER_UPDATE . $orderId;
                if (!$this->locks->lock($lock, self::LOCK_TIMEOUT)) {
                    throw new AdminEmailStaleEditException('The order is busy. Please try again.');
                }
                $acquired[] = $lock;
            }
            $this->editContext->activate($plan['order_ids'], $plan['customer_id'], $plan['account_email']);
            $connection = $this->resource->getConnection();
            $connection->beginTransaction();
            $started = true;
            if (!$this->orders instanceof ResetAfterRequestInterface) {
                throw new RuntimeException('Order repository does not support reset.');
            }
            $this->orders->_resetState();
            $this->customerRegistry->_resetState();
            $order = $this->orders->get($id);
            if (!$order instanceof Order) {
                throw new RuntimeException('Unexpected order implementation.');
            }
            $current = $this->planner->plan($id, $email, $create);
            if ($order->getCustomerEmail() != $oldEmail || $current['order_email'] !== $plan['order_email']
                || $current['customer_id'] !== $plan['customer_id'] || $current['account_email'] !== $plan['account_email']
                || $current['website_id'] !== $plan['website_id'] || $current['cascade'] !== $plan['cascade']
                || array_diff($current['order_ids'], $plan['order_ids']) !== []
            ) {
                throw new AdminEmailStaleEditException('The order changed. Refresh and try again.');
            }
            if ($email == $order->getCustomerEmail()) {
                throw new AdminEmailStaleEditException('Email address is the same as the old one.');
            }
            $reassigned = $this->editor->apply($order, $email, $plan['website_id'], $create, $assign);
            $comment = sprintf(
                (string)__('Order email address change from %s to %s by %s'),
                $oldEmail,
                $order->getCustomerEmail(),
                $this->authSession->getUser()->getUserName()
            );
            $order->addStatusHistoryComment($comment);
            foreach ($order->getAddresses() as $address) {
                $address->setEmail($order->getCustomerEmail());
            }
            $this->orders->save($order);
            $committing = true;
            $connection->commit();
            $committing = false;
            $committed = true;
            $result = [
                'error' => false,
                'message' => $reassigned ? __('Order reassigned to the existing customer.') : __('Email address successfully changed.'),
                'email' => $order->getCustomerEmail(),
                'ajaxExpired' => false,
            ];
        } catch (Throwable $error) {
            if ($started && !$committed) {
                try {
                    $this->resource->getConnection()->rollBack();
                } catch (Throwable $rollbackError) {
                    $this->logger->error('Admin email edit rollback failed', ['order_id' => $id, 'type' => $rollbackError::class]);
                }
            }
            if ($committing) {
                $result['message'] = __('The result could not be confirmed. Refresh before editing again.');
            } elseif ($error instanceof AdminEmailStaleEditException || $error instanceof AdminEmailInputException
                || $error instanceof NoSuchEntityException
            ) {
                $result['message'] = $error instanceof NoSuchEntityException ? __('Order not found.') : $error->getMessage();
            } else {
                $this->logger->error('Admin email edit failed', ['order_id' => $id, 'type' => $error::class]);
            }
        } finally {
            $this->editContext->_resetState();
            foreach (array_reverse($acquired) as $lock) {
                try {
                    if (!$this->locks->unlock($lock)) {
                        throw new RuntimeException('Lock release failed.');
                    }
                } catch (Throwable $error) {
                    $this->logger->error('Admin email edit lock cleanup failed', ['order_id' => $id, 'type' => $error::class]);
                }
            }
        }
        return $this->jsonFactory->create()->setData($result);
    }
}
