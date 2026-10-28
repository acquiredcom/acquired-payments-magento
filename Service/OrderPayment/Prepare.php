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

use Acquired\Payments\Api\Data\PaymentIntentInterface;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Exception\Api\SessionException;
use Acquired\Payments\Model\Api\GetPaymentConfirmParams;
use Acquired\Payments\Model\Payment\IntentFactory as PaymentIntentFactory;
use Acquired\Payments\Model\ResourceModel\Payment\Intent as PaymentIntentResource;
use Acquired\Payments\Service\GetPaymentSessionData;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Points the shopper's Acquired session at a placed order, so the payment is taken for exactly that order
 */
class Prepare
{
    /**
     * @param Gateway $gateway
     * @param CartRepositoryInterface $cartRepository
     * @param GetPaymentSessionData $getPaymentSessionData
     * @param GetPaymentConfirmParams $getPaymentConfirmParams
     * @param PaymentIntentFactory $paymentIntentFactory
     * @param PaymentIntentResource $paymentIntentResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly GetPaymentSessionData $getPaymentSessionData,
        private readonly GetPaymentConfirmParams $getPaymentConfirmParams,
        private readonly PaymentIntentFactory $paymentIntentFactory,
        private readonly PaymentIntentResource $paymentIntentResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param OrderInterface $order
     * @return array confirm params for acquired.confirmPayment()
     * @throws SessionException
     */
    public function execute(OrderInterface $order): array
    {
        $quote = $this->cartRepository->get((int)$order->getQuoteId());

        /** @var PaymentIntentInterface $paymentIntent */
        $paymentIntent = $this->paymentIntentFactory->create();
        $this->paymentIntentResource->load($paymentIntent, $quote->getId(), 'quote_id');

        if (!$paymentIntent->getSessionId()) {
            throw new SessionException(__('No Acquired session found for order #%1.', $order->getIncrementId()));
        }

        // The quote's totals are recalculated while the order is placed, so the order is the source of truth
        $payload = $this->getPaymentSessionData->executeForQuote($quote, (string)$order->getIncrementId());
        $payload['transaction']['amount'] = round((float)$order->getGrandTotal(), 2);
        $payload['transaction']['currency'] = strtolower((string)$order->getOrderCurrencyCode());

        try {
            $this->gateway->getComponent()->update($paymentIntent->getSessionId(), $payload);
        } catch (\Exception $e) {
            $this->logger->critical(
                __('Update Acquired session for order #%1 failed: %2', $order->getIncrementId(), $e->getMessage()),
                ['exception' => $e]
            );

            throw new SessionException(__('Update Acquired session failed!'));
        }

        return $this->getPaymentConfirmParams->buildForQuote($quote);
    }
}
