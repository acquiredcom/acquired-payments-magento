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
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Cancels an order-first order whose payment did not complete
 */
class Cancel
{
    private const VOIDED_TRANSACTION_ID = 'voided_transaction_id';

    /**
     * @param Gateway $gateway
     * @param OrderRepositoryInterface $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param ManagerInterface $eventManager
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly ManagerInterface $eventManager,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Only orders still in payment review are cancelled, so a payment that completed meanwhile is never undone.
     *
     * @param OrderInterface $order
     * @param string $reason added to the order history
     * @param bool $restoreQuote give the shopper their cart back; only when called from the shopper's session
     * @param string|null $voidTransactionId an Acquired transaction taken for this order that must be released
     * @return bool whether the order is now cancelled
     * @throws LocalizedException
     */
    public function execute(
        OrderInterface $order,
        string $reason,
        bool $restoreQuote = false,
        ?string $voidTransactionId = null
    ): bool {
        $lockName = 'acquired_order_payment_' . $order->getIncrementId();
        if (!$this->lockManager->lock($lockName, 30)) {
            throw new LocalizedException(__('Order #%1 is already being updated.', $order->getIncrementId()));
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$order->getEntityId());

            if ($order->getState() === Order::STATE_PAYMENT_REVIEW) {
                if ($voidTransactionId) {
                    $this->voidTransaction($order, $voidTransactionId);
                }

                $order->registerCancellation($reason);
                $this->eventManager->dispatch('order_cancel_after', ['order' => $order]);
                $this->orderRepository->save($order);
            }
        } finally {
            $this->lockManager->unlock($lockName);
        }

        $cancelled = $order->getState() === Order::STATE_CANCELED;

        if ($restoreQuote && $cancelled) {
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->restoreQuote();
        }

        return $cancelled;
    }

    /**
     * Void a payment that arrived for an order that is already cancelled
     *
     * @param OrderInterface $order
     * @param string $transactionId
     * @return void
     * @throws LocalizedException
     */
    public function releasePayment(OrderInterface $order, string $transactionId): void
    {
        $lockName = 'acquired_order_payment_' . $order->getIncrementId();
        if (!$this->lockManager->lock($lockName, 30)) {
            throw new LocalizedException(__('Order #%1 is already being updated.', $order->getIncrementId()));
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$order->getEntityId());
            if ($order->getPayment()->getAdditionalInformation(self::VOIDED_TRANSACTION_ID) === $transactionId) {
                return;
            }

            $this->voidTransaction($order, $transactionId);
            $this->orderRepository->save($order);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * @param Order $order
     * @param string $transactionId
     * @return void
     */
    private function voidTransaction(Order $order, string $transactionId): void
    {
        try {
            $response = $this->gateway->getTransaction()->void($transactionId);
            if (($response['status'] ?? '') === 'success') {
                $order->getPayment()->setAdditionalInformation(self::VOIDED_TRANSACTION_ID, $transactionId);
                $order->addCommentToStatusHistory(__('Acquired transaction %1 voided.', $transactionId));
                return;
            }

            $this->logger->critical(
                __('Void not confirmed for transaction %1 on order #%2', $transactionId, $order->getIncrementId()),
                ['response' => $response]
            );
        } catch (\Throwable $e) {
            $this->logger->critical(
                __('Void failed for transaction %1 on order #%2: %3', $transactionId, $order->getIncrementId(), $e->getMessage())
            );
        }

        $order->addCommentToStatusHistory(
            __('Acquired transaction %1 could not be voided. Check it in the Acquired hub.', $transactionId)
        );
    }
}
