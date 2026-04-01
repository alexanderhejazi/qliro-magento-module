<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Model\ContainerMapper;

class AddItemsToInvoiceRequest implements AdminAddItemsToInvoiceRequestInterface
{
    private string $merchantApiKey = '';
    private string $requestId = '';
    private string $currency = '';
    private int $orderId = 0;
    private int $paymentTransactionId = 0;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    private array $orderItems = [];

    private ContainerMapper $containerMapper;

    public function __construct(ContainerMapper $containerMapper)
    {
        $this->containerMapper = $containerMapper;
    }

    public function getMerchantApiKey()
    {
        return $this->merchantApiKey;
    }

    public function setMerchantApiKey($value)
    {
        $this->merchantApiKey = (string)$value;

        return $this;
    }

    public function getRequestId()
    {
        return $this->requestId;
    }

    public function setRequestId($value)
    {
        $this->requestId = (string)$value;

        return $this;
    }

    public function getCurrency()
    {
        return $this->currency;
    }

    public function setCurrency($value)
    {
        $this->currency = (string)$value;

        return $this;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $value)
    {
        $this->orderId = $value;

        return $this;
    }

    public function getPaymentTransactionId(): int
    {
        return $this->paymentTransactionId;
    }

    public function setPaymentTransactionId(int $value)
    {
        $this->paymentTransactionId = $value;

        return $this;
    }

    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    public function setOrderItems(array $value)
    {
        $this->orderItems = $value;

        return $this;
    }

    public function getAdditions(): array
    {
        $orderItems = [];
        foreach ($this->orderItems as $orderItem) {
            $itemData = $this->containerMapper->toArray($orderItem);
            if (!count($itemData)) {
                continue;
            }

            if (($itemData['Type'] ?? null) === 'Discount') {
                $itemData['PricePerItemIncVat'] = -abs((float)($itemData['PricePerItemIncVat'] ?? 0));
                $itemData['PricePerItemExVat'] = -abs((float)($itemData['PricePerItemExVat'] ?? 0));
            }

            $orderItems[] = $itemData;
        }

        if (!count($orderItems)) {
            return [];
        }

        return [[
            'PaymentTransactionId' => $this->paymentTransactionId,
            'OrderItems' => $orderItems,
        ]];
    }
}
