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

use Exception;
use Psr\Log\LoggerInterface;
use Acquired\Payments\Model\Api\CreateAcquiredCustomer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Acquired\Payments\Gateway\Config\Card\Config as CardConfig;
use Magento\Framework\UrlInterface;
use Acquired\Payments\Exception\Api\SessionException;
use Acquired\Payments\Service\MultishippingService;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Acquired\Payments\Service\GetTransactionAddressData;
use Acquired\Payments\Api\Data\TransactionAddressDataInterface;

class GetPaymentSessionData implements PaymentSessionDataInterface
{

    /**
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param StoreManagerInterface $storeManager
     * @param CardConfig $cardConfig
     * @param CreateAcquiredCustomer $createAcquiredCustomer
     * @param SerializerInterface $serializer
     * @param UrlInterface $urlBuilder
     * @param CartRepositoryInterface $cartRepository
     * @param LoggerInterface $logger
     * @param MultishippingService $multishippingService
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly CardConfig $cardConfig,
        private readonly CreateAcquiredCustomer $createAcquiredCustomer,
        private readonly SerializerInterface $serializer,
        private readonly UrlInterface $urlBuilder,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger,
        private readonly MultishippingService $multishippingService,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly GetTransactionAddressData $getTransactionAddressData
    ) {
    }

    /**
     * Get payload for creating checkout session on acquired
     *
     * @param string $orderId
     * @param array|null $customData
     * @return array|array[]
     * @throws SessionException
     */
    public function execute(string $orderId, ?array $customData = null): array
    {
        return $this->executeForQuote($this->checkoutSession->getQuote(), $orderId, $customData);
    }

    /**
     * Get payload for an Acquired checkout session for a specific quote, which may already be ordered
     *
     * @param CartInterface $quote
     * @param string $orderId
     * @param array|null $customData
     * @return array|array[]
     * @throws SessionException
     */
    public function executeForQuote(CartInterface $quote, string $orderId, ?array $customData = null): array
    {
        try {
            $payload = [
                'transaction' => [
                    'order_id' => $orderId,
                    'amount' => $this->priceCurrency->roundPrice($quote->getGrandTotal()),
                    'currency' => strtolower($this->storeManager->getStore()->getCurrentCurrencyCode()),
                    'capture' => $this->cardConfig->getCaptureAction()
                ]
            ];

            // if multishipping checkout, set capture to false, as we want to authorize only
            if ($quote->getIsMultiShipping()) {
                $orderIds = $this->multishippingService->reserveOrderIds($quote);

                if (empty($orderIds)) {
                    throw new Exception('No order ids found for multishipping');
                }

                $payload['transaction']['capture'] = false;
                $payload['transaction']['custom1'] = 'multishipping order';
                $payload['transaction']['custom2'] = implode(",", $orderIds);
                $payload['transaction']['order_id'] = $orderIds[0] . MultishippingService::MULTISHIPPING_ORDER_ID_SUFFIX;
            }

            if ($customData) {
                $payload['transaction']['custom_data'] = base64_encode($this->serializer->serialize($customData));
            }

            $contactUrl = $this->cardConfig->getTdsContactUrl() ?: '';
            $redirectUrl = $this->urlBuilder->getUrl('acquired/threedsecure/response');
            $webhookUrl = $this->urlBuilder->getUrl('acquired/webhook');

            if($this->cardConfig->isTdsActive()) {
                // if not https throw exception
                if (strpos($contactUrl, 'https://') === false) {
                    throw new Exception('Contact URL must be https');
                }
                if (strpos($redirectUrl, 'https://') === false) {
                    throw new Exception('Redirect URL must be https');
                }
                if (strpos($webhookUrl, 'https://') === false) {
                    throw new Exception('Webhook URL must be https');
                }
            }

            $payload['tds'] = [
                'is_active' => $this->cardConfig->isTdsActive(),
                'challenge_preference' => $this->cardConfig->getTdsChallengePreference(),
                'contact_url' =>  $contactUrl,
                'redirect_url' => $redirectUrl,
                'webhook_url' => $webhookUrl
            ];

            $payload['payment_methods'] = $this->getAvailablePaymentMethods();

            $payload['customer'] = [];
            /**
             * @var TransactionAddressDataInterface $addressData
             */
            $addressData = $this->getTransactionAddressData->execute($quote);
            $payload['customer']['billing'] = $addressData->getBilling();
            $payload['customer']['shipping'] = $addressData->getShipping() ?? [];

            if ($this->customerSession->isLoggedIn()) {
                $acquiredCustomer = $this->createAcquiredCustomer->execute($this->customerSession->getCustomerId());
                if($acquiredCustomer) {
                    $payload['customer']['customer_id'] = $acquiredCustomer['customer_id'];
                }

                if ($this->cardConfig->isCreateCardEnabled()) {
                    $payload['save_card'] = true;
                    $payload['payment']['reference'] = $this->customerSession->getCustomerId();
                } else {
                    $payload['save_card'] = false;
                }
            }
        } catch (Exception $e) {
            $message = __('Get Payment Session data failed: %1', $e->getMessage());
            $this->logger->critical($message, ['exception' => $e]);

            throw new SessionException(__($e->getMessage()));
        }

        return $payload;
    }

    protected function getAvailablePaymentMethods() : array {
        $paymentMethods = ['card'];

        if($this->cardConfig->isApplePayEnabled()) {
            $paymentMethods[] = 'apple_pay';
        }

        if($this->cardConfig->isGooglePayEnabled()) {
            $paymentMethods[] = 'google_pay';
        }

        return $paymentMethods;
    }
}
