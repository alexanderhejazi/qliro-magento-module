<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundDiscountBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundFeeBuilder;

class AddItemsToInvoiceBuilder
{
    private ?Payment $payment = null;
    private LinkRepositoryInterface $linkRepository;
    private LogManager $logManager;
    private Config $qliroConfig;
    private AdminAddItemsToInvoiceRequestInterfaceFactory $requestFactory;
    private QliroOrderItemInterfaceFactory $qliroOrderItemFactory;
    private QliroHelper $qliroHelper;
    private RefundFeeBuilder $refundFeeBuilder;
    private RefundDiscountBuilder $refundDiscountBuilder;
    private InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator;

    public function __construct(
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        Config $qliroConfig,
        AdminAddItemsToInvoiceRequestInterfaceFactory $requestFactory,
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        QliroHelper $qliroHelper,
        RefundFeeBuilder $refundFeeBuilder,
        RefundDiscountBuilder $refundDiscountBuilder,
        InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator
    ) {
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->qliroConfig = $qliroConfig;
        $this->requestFactory = $requestFactory;
        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->qliroHelper = $qliroHelper;
        $this->refundFeeBuilder = $refundFeeBuilder;
        $this->refundDiscountBuilder = $refundDiscountBuilder;
        $this->invoiceFeeTotalValidator = $invoiceFeeTotalValidator;
    }

    public function setPayment(Payment $payment)
    {
        $this->payment = $payment;

        return $this;
    }

    public function create(): AdminAddItemsToInvoiceRequestInterface
    {
        if (empty($this->payment)) {
            throw new \LogicException('Payment entity is not set.');
        }

        $request = $this->prepareRequest();
        $this->payment = null;

        return $request;
    }

    private function prepareRequest(): AdminAddItemsToInvoiceRequestInterface
    {
        /** @var AdminAddItemsToInvoiceRequestInterface $request */
        $request = $this->requestFactory->create();

        $order = $this->payment->getOrder();
        $creditMemo = $this->payment->getCreditmemo();

        try {
            $link = $this->linkRepository->getByOrderId($order->getId());

            $request->setMerchantApiKey(
                $this->qliroConfig->getMerchantApiKey($order->getStoreId())
            )->setOrderId(
                (int)$link->getQliroOrderId()
            )->setCurrency(
                $order->getOrderCurrencyCode()
            )->setPaymentTransactionId(
                (int)$this->payment->getParentTransactionId()
            )->setOrderItems(
                $this->getOrderItems($order, $creditMemo)
            );
        } catch (NoSuchEntityException|ClientException $e) {
            $this->logManager->debug(
                $e,
                [
                    'extra' => [
                        'order_id' => $order->getId(),
                        'quote_id' => $order->getQuoteId(),
                    ],
                ]
            );
        }

        return $request;
    }

    /**
     * @return QliroOrderItemInterface[]
     */
    private function getOrderItems(Order $order, Creditmemo $creditMemo): array
    {
        $orderItems = [];

        if ((float)$creditMemo->getShippingAmount() > 0) {
            $orderItems[] = $this->buildShippingItem($order, $creditMemo);
        }

        if ($this->invoiceFeeTotalValidator->setCreditMemo($creditMemo)->validate(true, true)) {
            $orderItems = array_merge($orderItems, $this->buildRefundedFeeItems($order));
        }

        return array_merge(
            $orderItems,
            $this->refundFeeBuilder->setCreditMemo($creditMemo)->create(),
            $this->refundDiscountBuilder->setCreditMemo($creditMemo)->create()
        );
    }

    private function buildShippingItem(Order $order, Creditmemo $creditMemo): QliroOrderItemInterface
    {
        $paymentAdditionalInfo = $order->getPayment()->getAdditionalInformation();
        $merchantReference = $paymentAdditionalInfo[ShippingFeeHandler::MERCHANT_REFERENCE_CODE_FIELD]
            ?? sprintf('ReturnShipping_%s', $order->getCreditmemosCollection()->getSize());

        $shippingAmount = (float)$creditMemo->getShippingAmount();
        $shippingTaxAmount = (float)$creditMemo->getShippingTaxAmount();
        $shippingExclTax = max(0.0, $shippingAmount - $shippingTaxAmount);

        $vatRate = 0.0;
        if ($shippingExclTax > 0.0 && $shippingTaxAmount > 0.0) {
            $vatRate = $this->qliroHelper->formatPrice(($shippingTaxAmount / $shippingExclTax) * 10000);
        }

        $item = $this->qliroOrderItemFactory->create();
        $item->setMerchantReference($merchantReference . '_refund');
        $item->setDescription('Shipping refund');
        $item->setType(QliroOrderItemInterface::TYPE_DISCOUNT);
        $item->setQuantity(1);
        $item->setPricePerItemIncVat((float)$this->qliroHelper->formatPrice(-abs($shippingAmount)));
        $item->setPricePerItemExVat((float)$this->qliroHelper->formatPrice(-abs($shippingExclTax)));
        $item->setVatRate((float)$vatRate);

        return $item;
    }

    /**
     * @return QliroOrderItemInterface[]
     */
    private function buildRefundedFeeItems(Order $order): array
    {
        $items = [];
        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');

        if (!is_array($qlirooneFees)) {
            return $items;
        }

        foreach ($qlirooneFees as $index => $qlirooneFee) {
            $merchantReference = (string)($qlirooneFee['MerchantReference'] ?? sprintf('ReturnFeeRefund_%s', $index + 1));
            $description = (string)($qlirooneFee['Description'] ?? 'Fee refund');
            $priceIncVat = (float)($qlirooneFee['PricePerItemIncVat'] ?? 0);
            $priceExVat = (float)($qlirooneFee['PricePerItemExVat'] ?? $priceIncVat);

            if ($priceIncVat <= 0) {
                continue;
            }

            $item = $this->qliroOrderItemFactory->create();
            $item->setMerchantReference($merchantReference . '_refund');
            $item->setDescription($description);
            $item->setType(QliroOrderItemInterface::TYPE_DISCOUNT);
            $item->setQuantity((float)($qlirooneFee['Quantity'] ?? 1));
            $item->setPricePerItemIncVat((float)$this->qliroHelper->formatPrice(-abs($priceIncVat)));
            $item->setPricePerItemExVat((float)$this->qliroHelper->formatPrice(-abs($priceExVat)));
            $item->setVatRate((float)($qlirooneFee['VatRate'] ?? 0));
            $items[] = $item;
        }

        return $items;
    }
}
