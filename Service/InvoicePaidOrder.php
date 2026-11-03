<?php

declare(strict_types=1);

/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */

namespace Acquired\Payments\Service;

use Acquired\Payments\Ui\Method\PayByBankProvider;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * @class InvoicePaidOrder
 *
 * Invoices a Pay By Bank order once Acquired has confirmed payment, at most once per order.
 */
class InvoicePaidOrder
{
    private const LOCK_PREFIX = 'acquired_invoice_';
    private const LOCK_TIMEOUT = 10;

    /**
     * @param LockManagerInterface $lockManager
     * @param OrderFactory $orderFactory
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly OrderFactory $orderFactory,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly InvoiceSender $invoiceSender
    ) {
    }

    /**
     * Invoice the order if it is still awaiting payment review.
     *
     * @param int $orderId
     * @param string $transactionId
     * @return array
     */
    public function execute(int $orderId, string $transactionId): array
    {
        $lockName = self::LOCK_PREFIX . $orderId;
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            return $this->createResponse(__('Order with ID %1 is being processed by another request.', $orderId), false, true);
        }

        try {
            // Load a fresh copy so a concurrent webhook or cron invoice is seen.
            $order = $this->orderFactory->create()->load($orderId);
            $incrementId = (string) $order->getIncrementId();

            if (!$this->isAwaitingPayment($order)) {
                return $this->createResponse(__('Order #%1 is not awaiting payment.', $incrementId));
            }

            $order->setState(Order::STATE_PROCESSING);

            if (!$order->canInvoice()) {
                return $this->createResponse(__('Order #%1 cannot be invoiced.', $incrementId));
            }

            return $this->invoice($order, $incrementId, $transactionId);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function isAwaitingPayment(Order $order): bool
    {
        return $order->getId()
            && $order->getPayment()
            && $order->getPayment()->getMethod() === PayByBankProvider::CODE
            && $order->getState() === Order::STATE_PAYMENT_REVIEW
            && !$order->hasInvoices();
    }

    /**
     * @param Order $order
     * @param string $incrementId
     * @param string $transactionId
     * @return array
     */
    private function invoice(Order $order, string $incrementId, string $transactionId): array
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $payment */
            $payment = $order->getPayment();
            $payment->setLastTransId($transactionId);
            $payment->setTransactionId($transactionId);

            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();

            // Persist invoice, order and payment together so a failure cannot leave an invoice on an unpaid order.
            $this->transactionFactory->create()
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->addObject($payment)
                ->save();

            $this->orderSender->send($order);
            $this->invoiceSender->send($invoice);
            $order->addCommentToStatusHistory(
                __('Notified customer about invoice creation #%1.', $invoice->getId())
            )->setIsCustomerNotified(true)->save();

            return $this->createResponse(__('Order #%1 invoiced successfully.', $incrementId), true);
        } catch (LocalizedException $e) {
            return $this->createResponse(__('Error invoicing order #%1: %2', $incrementId, $e->getMessage()), false, true);
        }
    }

    /**
     * @param \Magento\Framework\Phrase|string $message
     * @param bool $success
     * @param bool $retryable Whether the caller should retry later because the order may still need invoicing.
     * @return array
     */
    private function createResponse($message, bool $success = false, bool $retryable = false): array
    {
        return ['success' => $success, 'message' => (string) $message, 'retryable' => $retryable];
    }
}
