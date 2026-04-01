<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin AddItemsToInvoice request interface
 */
interface AdminAddItemsToInvoiceRequestInterface extends ContainerInterface
{
    public function getMerchantApiKey();

    public function setMerchantApiKey($value);

    public function getRequestId();

    public function setRequestId($value);

    public function getCurrency();

    public function setCurrency($value);

    public function getOrderId(): int;

    public function setOrderId(int $value);

    public function getPaymentTransactionId(): int;

    public function setPaymentTransactionId(int $value);

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setOrderItems(array $value);

    public function getAdditions(): array;
}
