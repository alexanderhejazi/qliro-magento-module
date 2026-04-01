<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;

class RefundDiscountBuilder
{
    /**
     * @var QliroOrderItemInterfaceFactory
     */
    private $qliroOrderItemFactory;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    /**
     * @var SharedVatRateResolver
     */
    private $sharedVatRateResolver;

    /**
     * @var QliroHelper
     */
    private $qliroHelper;

    /**
     * @var CreditmemoInterface
     */
    private $creditMemo;

    /**
     * Constructor method for the class.
     *
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory Factory for creating Qliro order items
     * @param ManagerInterface $eventManager Event manager for handling events
     * @param SharedVatRateResolver $sharedVatRateResolver
     * @param QliroHelper $qliroHelper
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        ManagerInterface $eventManager,
        SharedVatRateResolver $sharedVatRateResolver,
        QliroHelper $qliroHelper
    ) {
        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->eventManager = $eventManager;
        $this->sharedVatRateResolver = $sharedVatRateResolver;
        $this->qliroHelper = $qliroHelper;
    }

    /**
     * Sets the credit memo.
     *
     * @param CreditmemoInterface $creditMemo The credit memo instance to be set
     * @return $this
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo)
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * Creates and returns an array of processed data based on the current credit memo entity.
     * Throws a LogicException if the credit memo entity is not set.
     *
     * @return array Processed data from the credit memo entity, including discounts.
     * @throws \LogicException If the credit memo entity is not set.
     */
    public function create()
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }

        $container = $this->getDiscounts();
        $result = $container->getMerchantReference() ? [$container] : [];
        $this->creditMemo = null;

        return $result;
    }

    /**
     * Retrieves discount information for the current credit memo.
     *
     * This method creates a Qliro order item representing the discount applied
     * during the credit memo process. The discount is encapsulated as a single
     * item with relevant details such as price and type.
     *
     * @return QliroOrderItemInterface Returns an instance of QliroOrderItemInterface
     * containing discount details, including description, price, quantity, and type.
     */
    protected function getDiscounts()
    {
        $container = $this->qliroOrderItemFactory->create();


        if ($this->creditMemo->getAdjustmentPositive() > 0) {
            $priceIncVat = -abs((float)$this->creditMemo->getAdjustmentPositive());

            $container->setMerchantReference(
                sprintf("ReturnRefund_%s", $this->creditMemo->getOrder()->getCreditmemosCollection()->getSize())
            );
            $container->setDescription('Adjustment Refund');
            $container->setPricePerItemIncVat($priceIncVat);
            $container->setPricePerItemExVat($priceIncVat);
            $container->setQuantity(1);
            $container->setType(QliroOrderItemInterface::TYPE_DISCOUNT);

            $sharedVatRate = $this->sharedVatRateResolver->resolveForOrder($this->creditMemo->getOrder());
            if ($sharedVatRate !== null) {
                $priceExVat = $priceIncVat / (1 + ($sharedVatRate / 100));

                $container->setPricePerItemExVat((float)$this->qliroHelper->formatPrice($priceExVat));
                $container->setVatRate((float)$this->qliroHelper->formatPrice($sharedVatRate * 100));
            }

            $this->eventManager->dispatch(
                'qliroone_refund_discount_build_after',
                [
                    'credit_memo' => $this->creditMemo,
                    'container' => $container,
                ]
            );
        }

        return $container;
    }
}
