<?php

/**
 *
 * Acquired Limited Payment module (https://acquired.com/)
 *
 * Copyright (c) 2024 Acquired.com (https://acquired.com/)
 * See LICENSE.txt for license details.
 *
 *
 */

namespace Acquired\Payments\Model\Webhook\Processor;


use Magento\Sales\Model\Order;
use Acquired\Payments\Service\MultishippingService;
use Acquired\Payments\Controller\Hosted\Context as HostedContext;

/**
 * @class StatusUpdateProcessor
 *
 * Handles processing of "status_update" webhook notifications with integrity validation for multishipping orders.
 */
class MultishippingStatusUpdateProcessor extends AbstractProcessor
{

    /**
     * Processes webhook data by validating its version and integrity, then acts based on its type and status.
     *
     * @param array $webhookData The entire payload of the webhook data.
     * @param string $webhookHash The hash received in the webhook header for integrity validation.
     * @param string $webhookVersion The version of the webhook provided in the webhook header.
     * @return array Processing result including success status and a message.
     */
    public function process(array $webhookData, string $webhookHash, string $webhookVersion): array
    {
        $webhookBody = $webhookData['webhook_body'];
        $incrementId = $webhookBody['order_id'];

        // replace everything ending with multishipping suffix with ''
        if (strpos($incrementId, MultishippingService::MULTISHIPPING_ORDER_ID_SUFFIX)) {
            $incrementId = substr($incrementId, 0, strpos($incrementId, MultishippingService::MULTISHIPPING_ORDER_ID_SUFFIX));
        }

        // if retry, remove retry signature
        if (strpos($incrementId, HostedContext::HOSTED_ORDER_ID_RETRY_IDENTIFIER)) {
            // replace everything starting from the order retry identifier with ''
            $incrementId = substr($incrementId, 0, strpos($incrementId, HostedContext::HOSTED_ORDER_ID_RETRY_IDENTIFIER));
        }

        $order = $this->getOrderByIncrementId($incrementId);
        if (!$order) {
            return $this->createResponse(__('Order #%1 not found.', $incrementId));
        }

        $multishippingItem = $this->webhookContext->multishippingService->getMultishippingByReservedOrderId($order->getIncrementId());
        if (!$multishippingItem) {
            return $this->createResponse(__('Multishipping order #%1 not found.', $incrementId));
        }

        $multishippingItems = $this->webhookContext->multishippingService->getMultishippingByTransactionId($multishippingItem->getAcquiredTransactionId());

        $incrementIds = [];
        foreach ($multishippingItems as $mi) {
            $incrementIds[] = $mi->getQuoteReservedId();
        }

        $orderCollection = $this->webhookContext->orderCollectionFactory->create();
        $orderCollection->addFieldToSelect("*");
        $orderCollection->addFieldToFilter('increment_id', ['in' => $incrementIds]);

        $orders = $orderCollection->getItems();

        if ($webhookBody['status'] === self::STATUS_CANCELED) {
            return $this->bulkCancelOrders($orders, $webhookBody['transaction_id']);
        }

        if (
            ($webhookBody['status'] === self::STATUS_EXECUTED || $webhookBody['status'] === self::STATUS_SETTLED || $webhookBody['status'] === self::STATUS_SUCCESS)
        ) {
            return $this->bulkInvoiceOrders($orders, $webhookBody['transaction_id']);
        }

        return $this->createResponse(__('No action required for order #%1.', $incrementId));
    }

    protected function bulkCancelOrders($orders, $transactionId)
    {
        $success = true;
        $messages = [];

        foreach ($orders as $order) {
            $invoiceResult = $this->cancelOrder($order, $order->getIncrementId(), $transactionId);
            $success = $success && $invoiceResult['success'];
            $messages[] = $invoiceResult;
        }

        $response = [
            'success' => $success,
            'messages' => $messages
        ];

        return $response;
    }

    public function bulkInvoiceOrders($orders, $transactionId)
    {
        $success = true;
        $messages = [];

        foreach ($orders as $order) {
            $order->setState(Order::STATE_PROCESSING);
            $invoiceResult = $this->invoiceOrder($order, $order->getIncrementId(), $transactionId);
            $success = $success && $invoiceResult['success'];
            $messages[] = $invoiceResult;
        }

        $response = [
            'success' => $success,
            'messages' => $messages
        ];

        return $response;
    }
}
