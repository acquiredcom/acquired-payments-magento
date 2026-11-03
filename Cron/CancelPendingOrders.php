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

namespace Acquired\Payments\Cron;

use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Client\Transaction;
use Acquired\Payments\Controller\Hosted\Context as HostedContext;
use Acquired\Payments\Service\InvoicePaidOrder;
use Acquired\Payments\Service\MultishippingService;
use Acquired\Payments\Ui\Method\PayByBankProvider;
use Exception;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * @class CancelPendingOrders
 *
 * Settles Pay By Bank orders still awaiting payment after 24 hours: invoices those Acquired reports as paid
 * and cancels those with no payment.
 */
class CancelPendingOrders
{
    private const TIMEOUT = 24 * 3600;
    private const PAGE_SIZE = 50;
    private const LOCK_TIMEOUT = 10;
    private const CURSOR_FLAG = 'acquired_cancel_pending_orders_cursor';
    private const PAID_STATUSES = ['success', 'settled', 'executed'];
    private const NON_PAYMENT_TYPES = ['refund', 'void', 'credit', 'reversal'];

    /**
     * @param LoggerInterface $logger
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param FilterBuilder $filterBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param ManagerInterface $eventManager
     * @param Emulation $emulation
     * @param Gateway $gateway
     * @param InvoicePaidOrder $invoicePaidOrder
     * @param LockManagerInterface $lockManager
     * @param OrderFactory $orderFactory
     * @param MultishippingService $multishippingService
     * @param FlagManager $flagManager
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilder $filterBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ManagerInterface $eventManager,
        private readonly Emulation $emulation,
        private readonly Gateway $gateway,
        private readonly InvoicePaidOrder $invoicePaidOrder,
        private readonly LockManagerInterface $lockManager,
        private readonly OrderFactory $orderFactory,
        private readonly MultishippingService $multishippingService,
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * Execute the cron
     *
     * @return void
     */
    public function execute(): void
    {
        $this->logger->debug('Cron job CancelPendingOrders started.');

        foreach ($this->getPendingOrders() as $order) {
            $this->emulation->startEnvironmentEmulation((int) $order->getStoreId(), Area::AREA_FRONTEND, true);

            try {
                $this->processOrder($order);
            } catch (Exception $e) {
                $this->logger->critical(
                    __('Cron job CancelPendingOrders failed for order %1: %2', $order->getIncrementId(), $e->getMessage()),
                    ['exception' => $e]
                );
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        $this->logger->debug('Cron job CancelPendingOrders executed.');
    }

    /**
     * @param Order $order
     * @return void
     */
    private function processOrder(Order $order): void
    {
        $incrementId = (string) $order->getIncrementId();

        // Multishipping orders are sent to Acquired under a different order ID, so they keep the previous behaviour.
        if ($this->multishippingService->getMultishippingByReservedOrderId($incrementId)) {
            $this->cancelOrder((int) $order->getId());
            return;
        }

        $acquiredOrderIds = (array) ($order->getPayment()->getAdditionalInformation(HostedContext::ACQUIRED_ORDER_IDS) ?: [$incrementId]);

        $paidTransactionId = null;
        foreach ($acquiredOrderIds as $acquiredOrderId) {
            $transactions = $this->getTransactions((string) $acquiredOrderId);
            if ($transactions === null) {
                $this->logger->warning(__('Cron job CancelPendingOrders could not confirm payment for order %1, leaving it for the next run.', $incrementId));
                return;
            }

            $paidTransaction = $this->findPaidTransaction($transactions);
            if ($paidTransaction) {
                if (!$this->matchesOrderTotal($paidTransaction, $order)) {
                    $this->logger->critical(__('Cron job CancelPendingOrders found a payment for order %1 that does not match the order total, leaving it for review.', $incrementId));
                    return;
                }

                $paidTransactionId = (string) $paidTransaction['transaction_id'];
                break;
            }
        }

        if ($paidTransactionId) {
            $result = $this->invoicePaidOrder->execute((int) $order->getId(), $paidTransactionId);
            $this->logger->info(__('Cron job CancelPendingOrders invoicing order %1: %2', $incrementId, $result['message']));
            return;
        }

        $this->cancelOrder((int) $order->getId());
    }

    /**
     * Returns the transactions Acquired holds for the order ID, or null when the answer can't be trusted.
     *
     * @param string $acquiredOrderId
     * @return array|null
     */
    private function getTransactions(string $acquiredOrderId): ?array
    {
        try {
            $response = $this->gateway->getTransaction()->list([
                Transaction::ORDER_ID => $acquiredOrderId,
                Transaction::LIMIT => 100
            ]);
        } catch (Exception $e) {
            $this->logger->warning(__('Cron job CancelPendingOrders transaction lookup failed: %1', $e->getMessage()));
            return null;
        }

        if (!isset($response['meta']['total'], $response['data']) || !is_array($response['data'])) {
            return null;
        }

        if ((int) $response['meta']['total'] > count($response['data'])) {
            return null;
        }

        foreach ($response['data'] as $transaction) {
            if (($transaction['transaction']['order_id'] ?? null) !== $acquiredOrderId) {
                return null;
            }
        }

        return $response['data'];
    }

    /**
     * @param array $transactions
     * @return array|null
     */
    private function findPaidTransaction(array $transactions): ?array
    {
        foreach ($transactions as $transaction) {
            if (
                in_array($transaction['status'] ?? null, self::PAID_STATUSES, true)
                && !in_array($transaction['transaction_type'] ?? null, self::NON_PAYMENT_TYPES, true)
                && !empty($transaction['transaction_id'])
            ) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * @param array $transaction
     * @param Order $order
     * @return bool
     */
    private function matchesOrderTotal(array $transaction, Order $order): bool
    {
        $amount = $transaction['transaction']['amount'] ?? null;
        $currency = $transaction['transaction']['currency'] ?? null;

        return is_numeric($amount)
            && round((float) $amount, 2) === round((float) $order->getGrandTotal(), 2)
            && is_string($currency)
            && strtolower($currency) === strtolower((string) $order->getOrderCurrencyCode());
    }

    /**
     * Cancels the order under the same lock as invoicing, so a webhook arriving now can't be overwritten.
     *
     * @param int $orderId
     * @return void
     */
    private function cancelOrder(int $orderId): void
    {
        $lockName = InvoicePaidOrder::LOCK_PREFIX . $orderId;
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            return;
        }

        try {
            $order = $this->orderFactory->create()->load($orderId);
            if ($order->getState() !== Order::STATE_PAYMENT_REVIEW || $order->hasInvoices()) {
                return;
            }

            $this->logger->debug('Cron job CancelPendingOrders cancelling order ' . $order->getIncrementId());

            $order->registerCancellation('Order cancelled due to payment review timeout.');
            $this->eventManager->dispatch('order_cancel_after', ['order' => $order]);

            $this->orderRepository->save($order);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Returns the next batch after the last order checked, so orders that stay unresolved can't block newer ones.
     *
     * @return Order[]
     */
    private function getPendingOrders(): array
    {
        $lastOrderId = (int) $this->flagManager->getFlagData(self::CURSOR_FLAG);

        $methodFilter = $this->filterBuilder->setField('extension_attribute_payment_method.method')
            ->setConditionType('eq')
            ->setValue(PayByBankProvider::CODE)
            ->create();

        $stateFilter = $this->filterBuilder->setField('state')
            ->setConditionType('eq')
            ->setValue(Order::STATE_PAYMENT_REVIEW)
            ->create();

        $createdFilter = $this->filterBuilder->setField('created_at')
            ->setConditionType('lteq')
            ->setValue(gmdate('Y-m-d H:i:s', time() - self::TIMEOUT))
            ->create();

        $cursorFilter = $this->filterBuilder->setField('entity_id')
            ->setConditionType('gt')
            ->setValue($lastOrderId)
            ->create();

        $sortOrder = $this->sortOrderBuilder->setField('entity_id')
            ->setDirection(SortOrder::SORT_ASC)
            ->create();

        $searchCriteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilters([$methodFilter])
            ->addFilters([$stateFilter])
            ->addFilters([$createdFilter])
            ->addFilters([$cursorFilter])
            ->addSortOrder($sortOrder)
            ->setPageSize(self::PAGE_SIZE)
            ->setCurrentPage(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        $lastOrder = end($orders);
        $nextCursor = count($orders) < self::PAGE_SIZE || !$lastOrder ? 0 : (int) $lastOrder->getId();
        $this->flagManager->saveFlag(self::CURSOR_FLAG, $nextCursor);

        return $orders;
    }
}
