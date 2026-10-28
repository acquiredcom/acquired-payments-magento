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


use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Acquired\Payments\Controller\Hosted\Context as HostedContext;
use Acquired\Payments\Exception\Payment\PaymentVerificationException;
use Acquired\Payments\Observer\AcquiredCardDataAssignObserver;

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
            return $this->createResponse(__('Order #%1 not found.', $incrementId));
        }

        if ($webhookBody['status'] === self::STATUS_CANCELED) {
            return $this->cancelOrder($order, $incrementId);
        }

        if ($order->getPayment()->getAdditionalInformation(AcquiredCardDataAssignObserver::ORDER_FIRST)) {
            return $this->processOrderFirstPayment($order, $incrementId, $webhookBody);
        }

        if (
            ($webhookBody['status'] === self::STATUS_EXECUTED || $webhookBody['status'] === self::STATUS_SETTLED || $webhookBody['status'] === self::STATUS_SUCCESS)
            && $this->canInvoiceOrder($order)
        ) {
            $order->setState(Order::STATE_PROCESSING);
            return $this->invoiceOrder($order, $incrementId, $webhookBody['transaction_id']);
        }

        return $this->createResponse(__('No action required for order #%1.', $incrementId));
    }

    /**
     * Finishes card and Apple Pay express orders whose shopper never returned from paying
     *
     * @param OrderInterface $order
     * @param string $incrementId
     * @param array $webhookBody
     * @return array
     */
    private function processOrderFirstPayment(OrderInterface $order, string $incrementId, array $webhookBody): array
    {
        $paid = in_array(
            $webhookBody['status'],
            [self::STATUS_SUCCESS, self::STATUS_SETTLED, self::STATUS_EXECUTED],
            true
        );
        $transactionId = (string)($webhookBody['transaction_id'] ?? '');

        if (!$paid || $transactionId === '') {
            return $this->createResponse(__('No action required for order #%1.', $incrementId));
        }

        try {
            if ($order->getState() === Order::STATE_CANCELED) {
                $this->webhookContext->cancelOrderPayment->releasePayment($order, $transactionId);
                return $this->createResponse(__('Payment for cancelled order #%1 released.', $incrementId), true);
            }

            if ($order->getState() !== Order::STATE_PAYMENT_REVIEW) {
                return $this->createResponse(__('No action required for order #%1.', $incrementId));
            }

            $this->webhookContext->completeOrderPayment->execute($order, $transactionId);
            return $this->createResponse(__('Order #%1 payment completed.', $incrementId), true);
        } catch (PaymentVerificationException $e) {
            $this->webhookContext->cancelOrderPayment->execute(
                $order,
                (string)__('Payment could not be verified: %1', $e->getMessage()),
                false,
                $e->isPaymentTaken() ? $transactionId : null
            );
            return $this->createResponse(__('Order #%1 cancelled: %2', $incrementId, $e->getMessage()));
        } catch (LocalizedException $e) {
            return $this->createResponse(__('Error completing order #%1: %2', $incrementId, $e->getMessage()));
        }
    }
}
