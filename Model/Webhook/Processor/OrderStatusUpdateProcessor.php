<?php

/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */

namespace Acquired\Payments\Model\Webhook\Processor;


use Acquired\Payments\Controller\Hosted\Context as HostedContext;
use Acquired\Payments\Exception\Webhook\WebhookOrderNotFoundException;

/**
 * @class OrderStatusUpdateProcessor
 *
 * Handles processing of "status_update" webhook notifications with integrity validation for normal orders.
 */
class OrderStatusUpdateProcessor extends AbstractProcessor
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

        if (strpos($incrementId, HostedContext::HOSTED_ORDER_ID_RETRY_IDENTIFIER)) {
            // replace everything starting from 'R-' with ''
            $incrementId = substr($incrementId, 0, strpos($incrementId, HostedContext::HOSTED_ORDER_ID_RETRY_IDENTIFIER));
        }

        $order = $this->getOrderByIncrementId($incrementId);
        if (!$order) {
            throw new WebhookOrderNotFoundException(__('Order #%1 not found.', $incrementId));
        }

        if ($webhookBody['status'] === self::STATUS_CANCELED) {
            return $this->cancelOrder($order, $incrementId);
        }

        if (
            ($webhookBody['status'] === self::STATUS_EXECUTED || $webhookBody['status'] === self::STATUS_SETTLED || $webhookBody['status'] === self::STATUS_SUCCESS)
            && $this->canInvoiceOrder($order)
        ) {
            return $this->invoiceOrder($order, $incrementId, $webhookBody['transaction_id']);
        }

        return $this->createResponse(__('No action required for order #%1.', $incrementId));
    }
}
