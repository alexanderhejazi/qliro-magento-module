<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ReturnWithItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;

/**
 * QliroOne management class
 */
class Payment extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Api\Client\OrderManagementInterface
     */
    private $orderManagementApi;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
     */
    private $transactionBuilder;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory
     */
    private $orderManagementStatusInterfaceFactory;

    /**
     * @var OrderManagementStatusRepositoryInterface
     */
    private $orderManagementStatusRepository;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder
     */
    private $invoiceMarkItemsAsShippedRequestBuilder;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder
     */
    private $shipmentMarkItemsAsShippedRequestBuilder;

    /**
     * @var ReturnWithItemsBuilder
     */
    private $returnWithItemsBuilder;

    /**
     * @var AddItemsToInvoiceBuilder
     */
    private $addItemsToInvoiceBuilder;

    /**
     * Inject dependencies
     *
     * @param Config $qliroConfig
     * @param OrderManagementInterface $orderManagementApi
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param LogManager $logManager
     * @param BuilderInterface $transactionBuilder
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param InvoiceMarkItemsAsShippedRequestBuilder $invoiceMarkItemsAsShippedRequestBuilder
     * @param ShipmentMarkItemsAsShippedRequestBuilder $shipmentMarkItemsAsShippedRequestBuilder
     * @param ReturnWithItemsBuilder $returnWithItemsBuilder
     * @param AddItemsToInvoiceBuilder $addItemsToInvoiceBuilder
     */
    public function __construct(
        Config $qliroConfig,
        OrderManagementInterface $orderManagementApi,
        LinkRepositoryInterface $linkRepository,
        OrderRepositoryInterface $orderRepository,
        LogManager $logManager,
        BuilderInterface $transactionBuilder,
        OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        InvoiceMarkItemsAsShippedRequestBuilder $invoiceMarkItemsAsShippedRequestBuilder,
        ShipmentMarkItemsAsShippedRequestBuilder $shipmentMarkItemsAsShippedRequestBuilder,
        ReturnWithItemsBuilder $returnWithItemsBuilder,
        AddItemsToInvoiceBuilder $addItemsToInvoiceBuilder
    ) {
        $this->qliroConfig = $qliroConfig;
        $this->orderManagementApi = $orderManagementApi;
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderRepository = $orderRepository;
        $this->orderManagementStatusInterfaceFactory = $orderManagementStatusInterfaceFactory;
        $this->orderManagementStatusRepository = $orderManagementStatusRepository;
        $this->invoiceMarkItemsAsShippedRequestBuilder = $invoiceMarkItemsAsShippedRequestBuilder;
        $this->shipmentMarkItemsAsShippedRequestBuilder = $shipmentMarkItemsAsShippedRequestBuilder;
        $this->returnWithItemsBuilder = $returnWithItemsBuilder;
        $this->addItemsToInvoiceBuilder = $addItemsToInvoiceBuilder;
    }

    /**
     * Create payment transaction, which will hold and handle the Order Management features.
     * This saves payment and transaction, possibly also the order.
     *
     * This should have been done differently, with authorization keyword in method etc...
     *
     * @param Order $order
     * @param QliroOrderInterface $qliroOrder
     * @param string $state
     * @throws \Exception
     */
    public function createPaymentTransaction($order, $qliroOrder, $state = Order::STATE_PENDING_PAYMENT)
    {
        $this->logManager->setMark('PAYMENT TRANSACTION');

        try {
            /** @var \Magento\Sales\Model\Order\Payment $payment */
            $payment = $order->getPayment();

            $payment->setLastTransId($qliroOrder->getOrderId());
            $transactionId = 'qliroone-' . $qliroOrder->getOrderId();
            $payment->setTransactionId($transactionId);
            $payment->setIsTransactionClosed(false);

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('Qliro One authorized amount of %1.', $formattedPrice);

            /** @var \Magento\Sales\Api\Data\TransactionInterface $transaction */
            $transaction = $this->transactionBuilder->setPayment($payment)->setOrder($order)->setTransactionId(
                $payment->getTransactionId()
            )->build(\Magento\Sales\Api\Data\TransactionInterface::TYPE_AUTH);

            $payment->addTransactionCommentsToOrder($transaction, $message);
            $payment->setSkipOrderProcessing(true);
            $payment->save();

            if (empty($status)) {
                if ($order->getState() != $state) {
                    $order->setState($state);
                    $this->orderRepository->save($order);
                }
            } else {
                if ($order->getState() != $state || $order->getStatus() != $status) {
                    $order->setState($state)->setStatus($status);
                    $this->orderRepository->save($order);
                }
            }

            $transaction->save();
        } catch (\Exception $exception) {
            throw $exception;
        } finally {
            $this->logManager->setMark(null);
        }
    }

    /**
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function captureByInvoice($payment, $amount)
    {
        if ($payment->getData(self::QLIRO_SKIP_ACTUAL_CAPTURE)) {
            return;
        }

        /** @var Order $order */
        $order = $payment->getOrder();
        $link = $this->linkRepository->getByOrderId($order->getId());
        $this->logManager->setMerchantReference($link->getReference());

        $this->invoiceMarkItemsAsShippedRequestBuilder->setPayment($payment);
        $this->invoiceMarkItemsAsShippedRequestBuilder->setAmount($amount);

        $request = $this->invoiceMarkItemsAsShippedRequestBuilder->create();
        $result = $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());

        try {
            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setRecordId($payment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_PAYMENT);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Capture Requested for Invoice');
            $omStatus->setQliroOrderId($link->getQliroOrderId());

            $this->orderManagementStatusRepository->save($omStatus);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'payment_id' => $payment->getId(),
                    ],
                ]
            );
        }

        if ($result->getStatus() == 'Created') {
            if ($result->getPaymentTransactionId()) {
                $payment->setTransactionId($result->getPaymentTransactionId());
            }
        } else {
            throw new LocalizedException(
                __('Unable to capture payment for this order.')
            );
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return void
     * @throws \Exception
     */
    public function captureByShipment($shipment)
    {
        if (!$this->qliroConfig->shouldCaptureOnShipment($shipment->getStoreId())) {
            return;
        }

        if ($this->qliroConfig->shouldCaptureOnInvoice($shipment->getStoreId())) {
            // captureOnInvoice already sent MarkItemsAsShipped when the invoice was created.
            // Sending it again here would cause NO_ITEMS_LEFT_IN_RESERVATION from Qliro.
            return;
        }

        /** @var Order $order */
        $order = $shipment->getOrder();
        $link = $this->linkRepository->getByOrderId($order->getId());
        $this->logManager->setMerchantReference($link->getReference());

        $this->shipmentMarkItemsAsShippedRequestBuilder->setShipment($shipment);
        $request = $this->shipmentMarkItemsAsShippedRequestBuilder->create();

        if (count($request->getShipments()) == 0) {
            return;
        }

        $result = $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());

        try {
            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();

            $omStatus->setRecordId($shipment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_SHIPMENT);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Capture Requested for Shipment');
            $omStatus->setQliroOrderId($link->getQliroOrderId());

            $this->orderManagementStatusRepository->save($omStatus);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'shipment_id' => $shipment->getId(),
                    ],
                ]
            );
        }

        if ($result->getStatus() != 'Created') {
            throw new LocalizedException(
                __('Unable to mark items as shipped.')
            );
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param $amount
     * @return void
     * @throws LocalizedException
     */
    public function refundByInvoice($payment, $amount)
    {
        if (!$amount) {
            throw new LocalizedException(__('Zero amount is not allowed.'));
        }

        try {
            $link = $this->linkRepository->getByOrderId($payment->getOrder()->getId());

            if ($this->hasRefundedOrderItems($payment->getCreditmemo())) {
                $request = $this->returnWithItemsBuilder
                    ->setPayment($payment)
                    ->create();

                if (!$this->isValidReturnRequestAmount($request, $amount)) {
                    throw new LocalizedException(__('Request amount is not valid.'));
                }

                $result = $this->orderManagementApi->returnWithItems($request, $payment->getOrder()->getStoreId());
            } else {
                $request = $this->addItemsToInvoiceBuilder
                    ->setPayment($payment)
                    ->create();

                if (!$this->isValidAdditionsRequestAmount($request, $amount)) {
                    throw new LocalizedException(__('Request amount is not valid.'));
                }

                $result = $this->orderManagementApi->addItemsToInvoice($request, $payment->getOrder()->getStoreId());
            }

            try {
                /** @var OrderManagementStatus $omStatus */
                $omStatus = $this->orderManagementStatusInterfaceFactory->create();

                $omStatus->setRecordId($payment->getId());
                $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_REFUND);
                $omStatus->setTransactionId($result->getPaymentTransactionId());
                $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
                $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
                $omStatus->setMessage('Refund Requested');
                $omStatus->setQliroOrderId($link->getQliroOrderId());

                $this->orderManagementStatusRepository->save($omStatus);
            } catch (\Exception $exception) {
                $this->logManager->debug(
                    $exception,
                    [
                        'extra' => [
                            'payment_id' => $payment->getId(),
                        ],
                    ]
                );
            }

            if ($result->getStatus() != 'Created') {
                throw new LocalizedException(
                    __('Unable refund items')
                );
            }
        } catch (ClientException $e) {
            $this->logManager->debug(
                $e,
                [
                    'extra' => [
                        'order_id' => $payment->getOrder()->getId(),
                        'quote_id' => $payment->getOrder()->getQuoteId(),
                    ],
                ]
            );

            throw new LocalizedException(
                __('Unable refund items')
            );
        }
    }

    /**
     * Validate return request items and requested amount
     *
     * @param AdminReturnWithItemsRequestInterface $request
     * @param $amount
     * @return bool
     */
    private function isValidReturnRequestAmount(AdminReturnWithItemsRequestInterface $request, $amount)
    {
        return $this->isValidPayloadAmount($request->getReturns(), $amount);
    }

    /**
     * @param AdminAddItemsToInvoiceRequestInterface $request
     * @param $amount
     * @return bool
     */
    private function isValidAdditionsRequestAmount(AdminAddItemsToInvoiceRequestInterface $request, $amount)
    {
        $additions = $request->getAdditions();
        if (!count($additions) || !isset($additions[0]['OrderItems'])) {
            return false;
        }

        return $this->isValidPayloadAmount(['OrderItems' => $additions[0]['OrderItems']], $amount);
    }

    /**
     * @param array $payload
     * @param float|int|string $amount
     * @return bool
     */
    private function isValidPayloadAmount(array $payload, $amount)
    {
        $amount = floatval($amount);
        if (!count($payload)) {
            return false;
        }

        $sum = 0;
        foreach ($payload as $type => $return) {
            if (is_array($return) && isset($return['PricePerItemIncVat'])) {
                $sum += $this->normalizeItemAmount($return, $type);
                continue;
            }

            if (!is_array($return)) {
                continue;
            }

            foreach ($return as $inner) {
                if (is_array($inner) && isset($inner['PricePerItemIncVat'])) {
                    $sum += $this->normalizeItemAmount($inner, $type);
                }
            }
        }

        if (($sum * 100) != ($amount * 100)) { // fix php double type comparison issue
            return false;
        }

        return true;
    }

    /**
     * @param array $item
     * @param string $type
     * @return float
     */
    private function normalizeItemAmount(array $item, string $type): float
    {
        $sum = ((float)$item['PricePerItemIncVat']) * ((float)$item['Quantity']);

        if ($type === 'Fees' || (($item['Type'] ?? null) === 'Fee')) {
            return -abs($sum);
        }

        return abs($sum);
    }

    private function hasRefundedOrderItems(Creditmemo $creditMemo): bool
    {
        /** @var CreditmemoItem $item */
        foreach ($creditMemo->getItems() as $item) {
            if ((float)$item->getQty() <= 0) {
                continue;
            }

            $orderItem = $item->getOrderItem();
            if ($orderItem && $orderItem->isDummy()) {
                continue;
            }

            return true;
        }

        return false;
    }
}
