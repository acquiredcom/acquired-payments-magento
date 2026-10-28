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

use Exception;
use Psr\Log\LoggerInterface;
use Magento\Framework\UrlInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Acquired\Payments\Exception\Api\PaymentConfirmParamsException;
use Acquired\Payments\Model\Api\AcquiredSession;
use Acquired\Payments\Service\GetTransactionAddressData;
use Acquired\Payments\Api\Data\TransactionAddressDataInterface;

class GetPaymentConfirmParams
{

    /**
     *
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param UrlInterface $urlBuilder
     * @param LoggerInterface $logger
     * @param AcquiredSession $acquiredSession
     * @param GetTransactionAddressData $getTransactionAddressData
     *
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly AcquiredSession $acquiredSession,
        private readonly GetTransactionAddressData $getTransactionAddressData
    ) {
    }


    /**
     * Get payment confirm parameters
     *
     * @param string $nonce
     * @return array
     * @throws PaymentConfirmParamsException
     */
    public function execute(string $nonce): array
    {

        try {
            $confirmParams = $this->buildForQuote($this->checkoutSession->getQuote());

            // update acquired session
            $this->acquiredSession->prepareForPurchase($nonce);

            return $confirmParams;

        } catch(Exception $e) {
            $message = __('Get payment confirmation params failed: %1', $e->getMessage());
            $this->logger->critical($message, ['exception' => $e]);

            throw new PaymentConfirmParamsException($message);
        }
    }

    /**
     * Build confirm parameters from a quote, which may already be ordered
     *
     * @param CartInterface $quote
     * @return array
     */
    public function buildForQuote(CartInterface $quote): array
    {
        $customer = $this->customerSession->isLoggedIn()
            ? $this->customerRepository->getById($this->customerSession->getCustomerId())
            : null;

        $confirmParams['customer'] = [
            'webhook_url' => $this->urlBuilder->getUrl('acquired/webhook')
        ];

        if ($customer) {
            $confirmParams['reference'] = $customer->getExtensionAttributes()->getAcquiredCustomerId();
        }

        /**
         * @var TransactionAddressDataInterface $addressData
         */
        $addressData = $this->getTransactionAddressData->execute($quote);
        $confirmParams['customer']['billing'] = $addressData->getBilling();
        $confirmParams['customer']['shipping'] = $addressData->getShipping() ?? [];

        return $confirmParams;
    }
}
