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

namespace Acquired\Payments\Model\Api;

use Acquired\Payments\Api\Data\PaymentIntentInterface;
use Acquired\Payments\Api\Data\SessionDataInterface;
use Acquired\Payments\Api\SessionInterface;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Exception\Api\SessionException;
use Acquired\Payments\Model\Api\Response\SessionIdFactory;
use Acquired\Payments\Model\Payment\IntentFactory as PaymentIntentFactory;
use Acquired\Payments\Model\ResourceModel\Payment\Intent as PaymentIntentResource;
use Acquired\Payments\Service\PaymentSessionDataInterface;
use Acquired\Payments\Service\MultishippingService;
use Magento\Backend\Model\Session\Quote as BackendModelSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class AcquiredSession implements SessionInterface
{
    /**
     * @param SessionIdFactory $sessionIdFactory
     * @param PaymentSessionDataInterface $getPaymentSessionData
     * @param Gateway $gateway
     * @param LoggerInterface $logger
     * @param CheckoutSession $checkoutSession
     * @param BackendModelSession $backendQuoteSession
     * @param CartRepositoryInterface $cartRepository
     * @param State $state
     * @param PaymentIntentFactory $paymentIntentFactory
     * @param PaymentIntentResource $paymentIntentResource
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly Response\SessionIdFactory $sessionIdFactory,
        private readonly PaymentSessionDataInterface $getPaymentSessionData,
        private readonly Gateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly CheckoutSession $checkoutSession,
        private readonly BackendModelSession $backendQuoteSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly State $state,
        private readonly PaymentIntentFactory $paymentIntentFactory,
        private readonly PaymentIntentResource $paymentIntentResource,
        private readonly SerializerInterface $serializer,
        private readonly MultishippingService $multishippingService
    ) {}

    /**
     * @return BackendModelSession|CheckoutSession
     * @throws LocalizedException
     */
    protected function getCheckoutSession()
    {
        return $this->state->getAreaCode() === 'adminhtml' ? $this->backendQuoteSession : $this->checkoutSession;
    }

    /**
     * Create a fingerprint for the payment data
     *
     * @param array $paymentData
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function createFingerprint(array $paymentData): string
    {
        // remove order_id from payment data as it is nonce specific, use quote id instead for fingerprint
        unset($paymentData['transaction']['order_id']);
        $paymentData['transaction']['order_id'] = $this->getQuote()->getId();

        // remove transaction amount to allow updates to the quote without recreating a new session
        unset($paymentData['transaction']['amount']);

        // remove address data from fingerprint
        unset($paymentData['customer']['billing']);
        unset($paymentData['customer']['shipping']);

        return md5(trim(json_encode($paymentData)));
    }

    /**
     * @param array $payload
     * @param string $nonce
     * @return SessionDataInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws SessionException
     */
    protected function createNewPaymentIntent(array $payload, string $nonce): SessionDataInterface
    {
        $quote = $this->getQuote();
        $paymentIntent = $this->getPaymentIntentByQuote($quote);

        if (!$quote->getReservedOrderId() || $nonce !== $paymentIntent->getNonce()) {
            $quote->setReservedOrderId(null);
            $quote->reserveOrderId();
            $this->cartRepository->save($quote);
        }

        try {
            $response = $this->gateway->getComponent()->create(
                $payload
            );
            $sessionId = (string) $response['session_id'];
            $fingerprint = $this->createFingerprint($payload);

            $paymentIntent->setQuoteId((int) $quote->getId());
            $paymentIntent->setSessionId($sessionId);
            $paymentIntent->setNonce($nonce);
            $paymentIntent->setFingerprint($fingerprint);
            $paymentIntent->setFingerprintData($this->serializer->serialize($payload));
            $this->paymentIntentResource->save($paymentIntent);

            /** @var SessionDataInterface $acquiredSession */
            $acquiredSession = $this->sessionIdFactory->create();
            $acquiredSession->setSessionId($sessionId);
        } catch (\Exception $e) {
            $this->logger->critical(
                __('Create Acquired session failed: %1', $e->getMessage()),
                ['exception' => $e]
            );

            $this->paymentIntentResource->delete($paymentIntent);
            $this->multishippingService->clearMultishippingData($quote);

            throw new SessionException(__('Create Acquired session failed!'));
        }

        return $acquiredSession;
    }

    /**
     * @param string $sessionId
     * @param array $payload
     * @param string $nonce
     * @return SessionDataInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws SessionException
     */
    protected function updatePaymentIntent(string $sessionId, array $payload, string $nonce): SessionDataInterface
    {
        $quote = $this->getQuote();
        $paymentIntent = $this->getPaymentIntentByQuote($quote);

        if (!$quote->getReservedOrderId() || $nonce !== $paymentIntent->getNonce()) {
            $quote->setReservedOrderId(null);
            $quote->reserveOrderId();
            $this->cartRepository->save($quote);
        }

        try {
            $this->gateway->getComponent()->update(
                $sessionId,
                $payload
            );
            $fingerprint = $this->createFingerprint($payload);

            $paymentIntent->setSessionId($sessionId);
            $paymentIntent->setNonce($nonce);
            $paymentIntent->setFingerprint($fingerprint);
            $paymentIntent->setFingerprintData($this->serializer->serialize($payload));
            $this->paymentIntentResource->save($paymentIntent);

            $acquiredSession = $this->sessionIdFactory->create();
            $acquiredSession->setSessionId($sessionId);
        } catch (\Exception $e) {
            $this->logger->critical(
                __('Update Acquired session failed: %1', $e->getMessage()),
                ['exception' => $e]
            );

            throw new SessionException(__('Update Acquired session failed!'));
        }

        return $acquiredSession;
    }

    /**
     * @param string $nonce
     * @param mixed|null $customData
     * @return SessionDataInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws SessionException
     */
    public function get(string $nonce, ?array $customData = null): SessionDataInterface
    {
        $quote = $this->getQuote();

        $paymentIntent = $this->getPaymentIntentByQuote($quote);
        $paymentData = $this->getPaymentSessionData->execute(
            sprintf('%s_%s', $quote->getId(), $nonce),
            $customData ?? []
        );

        if ($paymentIntent->getPaymentIntentId()
            && $nonce === $paymentIntent->getNonce()
            && $this->isFingerprintValid($paymentData, $paymentIntent->getFingerprint())
        ) {
            $acquiredSession = $this->sessionIdFactory->create();
            $acquiredSession->setSessionId($paymentIntent->getSessionId());
            return $acquiredSession;
        }

        /**
         * If there is no payment intent OR fingerprint or nonce are different,
         *       create new payment intent, use the new nonce to generate a new order id
         */
        return $this->createNewPaymentIntent($paymentData, $nonce);
    }

    /**
     * @param string $nonce
     * @param string $sessionId
     * @param mixed|null $customData
     * @return SessionDataInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws SessionException
     */
    public function update(string $nonce, string $sessionId, ?array $customData = null): SessionDataInterface
    {
        $quote = $this->getQuote();

        $paymentIntent = $this->getPaymentIntentByQuote($quote);
        $paymentData = $this->getPaymentSessionData->execute(
            sprintf('%s_%s', $quote->getId(), $nonce),
            $customData ?? []
        );

        $sessionId = $paymentIntent->getSessionId();

        // if fingerprint matches we update the session with new transaction id, nonce doesn't matter
        if ($sessionId && $this->isFingerPrintValid($paymentData, $paymentIntent->getFingerprint())) {
            return $this->updatePaymentIntent($sessionId, $paymentData, $nonce);
        }

        // otherwise we throw an error as nonce changed or the payload fingerprint is invalid
        throw new SessionException(__('Session ID does not match the current session'));
    }

    /**
     * Prepare the session for purchase, consuming the nonce and incrementing the order id
     *
     * @param string $nonce
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws SessionException
     */
    public function prepareForPurchase(string $nonce): void
    {
        $quote = $this->getQuote();
        $paymentIntent = $this->getPaymentIntentByQuote($quote);

        if ($nonce !== $paymentIntent->getNonce()) {
            throw new SessionException(__('Nonce does not match the current session!'));
        }

        if (!$quote->getReservedOrderId()) {
            $quote->setReservedOrderId(null);
            $quote->reserveOrderId();
            $this->cartRepository->save($quote);
        }
        $paymentData = $this->getPaymentSessionData->execute(
            $quote->getReservedOrderId()
        );

        try {
            $this->gateway->getComponent()->update(
                $paymentIntent->getSessionId(),
                $paymentData
            );
        } catch (\Exception $e) {
            $this->logger->critical(
                __('Update Acquired session failed: %1', $e->getMessage()),
                ['exception' => $e]
            );

            throw new SessionException(__('Update Acquired session failed!'));
        }
    }

    /**
     * Get the current quote
     *
     * @return Quote
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getQuote(): Quote
    {
        return $this->getCheckoutSession()->getQuote();
    }

    /**
     * Validate the fingerprint
     *
     * @param array $paymentData
     * @param string $currentFingerprint
     * @return bool
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function isFingerprintValid(array $paymentData, string $currentFingerprint): bool
    {
        // create fingerprint
        $payloadFingerprint = $this->createFingerprint($paymentData);
        // compare fingerprint with current fingerprint
        return $currentFingerprint === $payloadFingerprint;
    }

    /**
     * @param CartInterface $quote
     * @return PaymentIntentInterface
     */
    protected function getPaymentIntentByQuote(CartInterface $quote): PaymentIntentInterface
    {
        /** @var PaymentIntentInterface $paymentIntent */
        $paymentIntent = $this->paymentIntentFactory->create();
        $this->paymentIntentResource->load($paymentIntent, $quote->getId(), 'quote_id');

        return $paymentIntent;
    }
}
