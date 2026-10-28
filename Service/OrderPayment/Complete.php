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

namespace Acquired\Payments\Service\OrderPayment;

use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Controller\Hosted\Context as HostedContext;
use Acquired\Payments\Exception\Payment\PaymentVerificationException;
use Acquired\Payments\Gateway\Config\Card\Config as CardConfig;
use Acquired\Payments\Gateway\Response\Card\TransactionDataMapper;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Psr\Log\LoggerInterface;

/**
 * Confirms an order-first payment with Acquired and moves the order out of payment review
 */
class Complete
{
    private const PAID_STATUSES = ['success', 'settled', 'executed'];

    /**
     * @param Gateway $gateway
     * @param OrderRepositoryInterface $orderRepository
     * @param TransactionDataMapper $transactionDataMapper
     * @param TransactionFactory $transactionFactory
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     * @param LockManagerInterface $lockManager
     * @param CardConfig $cardConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TransactionDataMapper $transactionDataMapper,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly InvoiceSender $invoiceSender,
        private readonly LockManagerInterface $lockManager,
        private readonly CardConfig $cardConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Does nothing if the order has already left payment review, as the shopper's browser and the webhook can
     * both complete the same order.
     *
     * @param OrderInterface $order
     * @param string $transactionId
     * @return void
     * @throws PaymentVerificationException Acquired's transaction is not a successful payment for this order
     * @throws LocalizedException the payment could not be confirmed, the order is left in payment review
     */
    public function execute(OrderInterface $order, string $transactionId): void
    {
        $lockName = 'acquired_order_payment_' . $order->getIncrementId();
        if (!$this->lockManager->lock($lockName, 30)) {
            throw new LocalizedException(__('Order #%1 is already being updated.', $order->getIncrementId()));
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$order->getEntityId());
            if ($order->getState() !== Order::STATE_PAYMENT_REVIEW) {
                return;
            }

            $transaction = $this->gateway->getTransaction()->get($transactionId);
            if (!$transaction) {
                throw new LocalizedException(__('Acquired transaction %1 could not be retrieved.', $transactionId));
            }
            $this->verify($order, $transactionId, $transaction);

            /** @var Order\Payment $payment */
            $payment = $order->getPayment();
            $this->transactionDataMapper->map($payment, $transaction);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);

            $amount = (float)$order->getBaseGrandTotal();
            if ($this->isCaptured($transaction)) {
                $payment->registerCaptureNotification($amount, true);
            } else {
                $payment->registerAuthorizationNotification($amount);
            }

            $invoice = $payment->getCreatedInvoice();
            $save = $this->transactionFactory->create()->addObject($order);
            if ($invoice) {
                $save->addObject($invoice);
            }
            $save->save();
        } finally {
            $this->lockManager->unlock($lockName);
        }

        $this->sendEmails($order);
    }

    /**
     * @param Order $order
     * @param string $transactionId
     * @param array $transaction
     * @return void
     * @throws PaymentVerificationException
     */
    private function verify(Order $order, string $transactionId, array $transaction): void
    {
        $incrementId = $order->getIncrementId();
        $status = (string)($transaction['status'] ?? '');

        if (!in_array($status, self::PAID_STATUSES, true)) {
            throw new PaymentVerificationException(
                __('Acquired transaction %1 for order #%2 has status "%3".', $transactionId, $incrementId, $status)
            );
        }

        if (($transaction['transaction_id'] ?? null) !== $transactionId) {
            throw PaymentVerificationException::paymentTaken(
                __('Acquired returned a different transaction for %1.', $transactionId)
            );
        }

        $transactionOrderId = (string)($transaction['transaction']['order_id'] ?? '');
        $retryPosition = strpos($transactionOrderId, HostedContext::HOSTED_ORDER_ID_RETRY_IDENTIFIER);
        if ($retryPosition !== false) {
            $transactionOrderId = substr($transactionOrderId, 0, $retryPosition);
        }
        if ($transactionOrderId !== $incrementId) {
            throw PaymentVerificationException::paymentTaken(
                __('Acquired transaction %1 belongs to order "%2", not #%3.', $transactionId, $transactionOrderId, $incrementId)
            );
        }

        $amount = $transaction['transaction']['amount'] ?? null;
        if ($amount === null || abs((float)$amount - (float)$order->getGrandTotal()) > 0.005) {
            throw PaymentVerificationException::paymentTaken(
                __('Acquired transaction %1 is for %2, but order #%3 totals %4.', $transactionId, $amount, $incrementId, $order->getGrandTotal())
            );
        }

        $currency = $transaction['transaction']['currency'] ?? null;
        if ($currency !== null && strtolower((string)$currency) !== strtolower((string)$order->getOrderCurrencyCode())) {
            throw PaymentVerificationException::paymentTaken(
                __('Acquired transaction %1 is in %2, but order #%3 is in %4.', $transactionId, $currency, $incrementId, $order->getOrderCurrencyCode())
            );
        }
    }

    /**
     * @param array $transaction
     * @return bool
     */
    private function isCaptured(array $transaction): bool
    {
        $type = strtolower((string)($transaction['transaction_type'] ?? ''));
        if ($type !== '') {
            return str_contains($type, 'capture');
        }

        return $this->cardConfig->getCaptureAction();
    }

    /**
     * A failed email must not undo a completed payment
     *
     * @param Order $order
     * @return void
     */
    private function sendEmails(Order $order): void
    {
        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }

            $invoice = $order->getPayment()->getCreatedInvoice();
            if ($invoice) {
                $this->invoiceSender->send($invoice);
            }
        } catch (\Throwable $e) {
            $this->logger->critical(
                __('Order #%1 was paid but the confirmation email failed: %2', $order->getIncrementId(), $e->getMessage()),
                ['exception' => $e]
            );
        }
    }
}
