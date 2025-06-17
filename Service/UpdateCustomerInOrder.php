<?php

declare(strict_types=1);

namespace MagePal\EditOrderEmail\Service;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Sales\Api\Data\OrderInterface;

class UpdateCustomerInOrder
{
    public function __construct() {
    }

    public function update(OrderInterface &$order, CustomerInterface $customer): OrderInterface
    {
        $order->setCustomerEmail($customer->getEmail());
        $order->setCustomerId($customer->getId());
        $order->setCustomerGroupId($customer->getGroupId());
        $order->setCustomerIsGuest(0);

        return $order;
    }
}
