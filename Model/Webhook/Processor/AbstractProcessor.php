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

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Acquired\Payments\Exception\Webhook\WebhookVersionException;
use Acquired\Payments\Exception\Webhook\WebhookIntegrityException;
use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Model\Webhook\Context as WebhookContext;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Acquired\Payments\Ui\Method\PayByBankProvider;

/**
 * @class AbstractProcessor
 *
 * Provides a foundation for processing and integrity validation of different types of webhook notifications.
 */
abstract class AbstractProcessor
{

    private const WEBHOOK_VERSION = '2';
    private const HMAC_ALGORITHM = 'sha256';
    protected const STATUS_CANCELED = 'cancelled';
    protected const STATUS_SUCCESS = 'success';
    protected const STATUS_SETTLED = 'settled';
    protected const STATUS_EXECUTED = 'executed';

    /**
     * @param Basic $basicConfig
     * @param SerializerInterface $serializer
     */
    public function __construct(
        protected readonly Basic $basicConfig,
        protected readonly SerializerInterface $serializer,
        protected readonly WebhookContext $webhookContext
    ) {}

    /**
     * Validates the webhook data by checking its version and integrity before proceeding to the process method.
     *
     * @param array $webhookData The entire payload of the webhook data.
     * @param string $webhookHash The hash received in the webhook header for integrity validation.
     * @param string $webhookVersion The version of the webhook provided in the webhook header.
     * @return array Returns the result from the process method which handles the specific logic based on the webhook type.
     * @throws WebhookVersionException If the provided webhook version does not match the required version.
     * @throws WebhookIntegrityException If the integrity check of the webhook data fails.
     */
    final public function execute(array $webhookData, string $webhookHash, string $webhookVersion): array
    {
        if (!$this->validateWebhookVersion($webhookVersion)) {
            throw new WebhookVersionException(__('The webhook version is not appropriate. Version 2 is required.'));
        }

        if (!$this->validateIntegrity($webhookData, $webhookHash)) {
            throw new WebhookIntegrityException(__('Failed integrity check.'));
        }

        return $this->process($webhookData, $webhookHash, $webhookVersion);
    }

    /**
     * Validate the integrity of the webhook data
     *
     * @param array $webhookData The entire webhook payload data.
     * @return bool True if the integrity check passes, false otherwise.
     */
    private function validateIntegrity(array $webhookData, string $webhookHash): bool
    {
        try {
            $serializedData = $this->serializer->serialize($webhookData);
            $generatedHash = hash_hmac(self::HMAC_ALGORITHM, $serializedData, $this->basicConfig->getApiSecret());

        } catch (Exception $e) {
            return false;
        }

        return hash_equals($generatedHash, $webhookHash);
    }

    /**
     * Validates if the provided webhook version matches the expected version.
     *
     * @param string $webhookVersion The version of the webhook provided in the webhook header.
     * @return bool True if the version matches the expected version, false otherwise.
     */
    private function validateWebhookVersion(string $webhookVersion): bool
    {
        return $webhookVersion == self::WEBHOOK_VERSION;
    }

    /**
     * Process the webhook data.
     *
     * @param array $webhookData The entire payload of the webhook data.
     * @param string $webhookHash The hash received in the webhook header for integrity validation.
     * @param string $webhookVersion The version of the webhook provided in the webhook header.
     * @return array Specific response
     */
    abstract protected function process(array $webhookData, string $webhookHash, string $webhookVersion): array;

    /**
     * Creates a standardized response array for the process outcome.
     *
     * @param string $message The message describing the outcome of the process.
     * @param bool $success Optional. Indicates whether the process was successful. Default is false.
     * @param bool $retryable Optional. Whether Acquired should redeliver the webhook. Default is false.
     * @return array Associative array containing the success status and message.
     */
    protected function createResponse(string $message, bool $success = false, bool $retryable = false): array
    {
        return ['success' => $success, 'message' => $message, 'retryable' => $retryable];
    }

    protected function canInvoiceOrder(OrderInterface $order): bool
    {
        // check payment method and status
        $payment = $order->getPayment();
        if ($payment->getMethod() !== PayByBankProvider::CODE) {
            return false;
        }

        if ($order->getState() !== Order::STATE_PAYMENT_REVIEW) {
            return false;
        }

        return true;
    }

    /**
     * Creates an invoice for the provided order entity and increment ID.
     *
     * @param OrderInterface $order The order entity to be cancelled.
     * @param string $incrementId The increment ID of the order to provide context in the response.
     * @param string $transactionId New and latest Acquired transaction Id.
     * @param bool $isMultishipping Whether the order is part of a multishipping checkout.
     * @return array Associative array indicating the success status and message of the cancellation action.
     */
    protected function invoiceOrder(OrderInterface $order, string $incrementId, string $transactionId, bool $isMultishipping = false): array
    {
        return $this->webhookContext->invoicePaidOrder->execute((int) $order->getEntityId(), $transactionId, $isMultishipping);
    }

    /**
     * Cancels an order based on the provided order entity and increment ID.
     *
     * @param OrderInterface $order The order entity to be cancelled.
     * @param string $incrementId The increment ID of the order to provide context in the response.
     * @return array Associative array indicating the success status and message of the cancellation action.
     */
    protected function cancelOrder(OrderInterface $order, string $incrementId): array
    {
        if (!$order->canCancel()) {
            return $this->createResponse(__('Order #%1 cannot be cancelled.', $incrementId));
        }

        try {
            $this->webhookContext->orderManagement->cancel($order->getEntityId());
            return $this->createResponse( __('Order #%1 cancelled successfully.', $incrementId), true);
        } catch (LocalizedException $e) {
            return $this->createResponse( __('Error cancelling order #%1: %2', $incrementId, $e->getMessage()), false, true);
        }
    }

    /**
     * Retrieves an order by its increment ID.
     *
     * @param string $incrementId The increment ID of the order to retrieve.
     * @return OrderInterface|null Returns the order object if found, otherwise null.
     */
    protected function getOrderByIncrementId(string $incrementId): ?OrderInterface
    {
        $searchCriteria = $this->webhookContext->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orderList = $this->webhookContext->orderRepository->getList($searchCriteria)->getItems();
        return array_shift($orderList) ?: null;
    }

}
