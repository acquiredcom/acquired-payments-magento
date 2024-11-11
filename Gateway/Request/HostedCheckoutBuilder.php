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

namespace Acquired\Payments\Gateway\Request;


use Exception;
use Psr\Log\LoggerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Framework\UrlInterface;
use Acquired\Payments\Exception\Command\BuilderException;
use Acquired\Payments\Gateway\Config\Hosted\Config as HostedConfig;
use Magento\Store\Model\StoreManagerInterface;
use Acquired\Payments\Api\AcquiredCustomerRepositoryInterface;
use Acquired\Payments\Model\Api\CreateAcquiredCustomer;
use Acquired\Payments\Service\GetTransactionAddressData;
use Acquired\Payments\Api\Data\TransactionAddressDataInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\Store;
use Magento\Quote\Model\Quote;
use Magento\Quote\Api\CartRepositoryInterface;

class HostedCheckoutBuilder implements BuilderInterface
{

    /**
     *
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param HostedConfig $hostedConfig
     * @param CreateAcquiredCustomer $createAcquiredCustomer
     * @param GetTransactionAddressData $getTransactionAddressData
     * @param CartRepositoryInterface $quoteRepository
     *
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly HostedConfig $hostedConfig,
        private readonly CreateAcquiredCustomer $createAcquiredCustomer,
        private readonly GetTransactionAddressData $getTransactionAddressData,
        private readonly CartRepositoryInterface $quoteRepository
    ) {
    }

    /**
     * @param array $buildSubject
     * @return array
     * @throws BuilderException
     */
    public function build(array $buildSubject): array
    {
        try {
            $payment = SubjectReader::readPayment($buildSubject)->getPayment();
            $order = $payment instanceof \Magento\Sales\Model\Order\Payment ? $payment->getOrder() : SubjectReader::readPayment($buildSubject)->getOrder();
            $quote = $this->quoteRepository->get($order->getQuoteId());
            $amount = (float)SubjectReader::readAmount($buildSubject);

            $isMultiShipping = $quote instanceof Quote ? $quote->getIsMultiShipping() : false;

            if ($isMultiShipping) {
                $order->setMultishippingAcquiredTransactionId('M-' . $order->getQuoteId());
            }

            $customData = [];
            if ($order->getCustomerId()) {
                $customData['customer_id'] = $order->getCustomerId();
            }

            if (strpos($this->urlBuilder->getUrl($this->hostedConfig->getRedirectUrl()), 'https://') === false) {
                throw new BuilderException(__('Redirect URL must be HTTPS: %1', $this->urlBuilder->getUrl($this->hostedConfig->getRedirectUrl())));
            }
            if (strpos($this->urlBuilder->getUrl($this->hostedConfig->getWebhookUrl()), 'https://') === false) {
                throw new BuilderException(__('Webhook URL must be HTTPS: %1', $this->urlBuilder->getUrl($this->hostedConfig->getWebhookUrl())));
            }

            return $this->getData((int) $order->getQuoteId(), $order->getIncrementId(), $amount, $customData);
        } catch (Exception $e) {
            $message = __('Authorize build failed: %1', $e->getMessage());
            $this->logger->critical($message, ['exception' => $e]);

            throw new BuilderException($message);
        }
    }

    /**
     * Builds transaction data for hosted checkout flow
     *
     * @param int $quote
     * @param string $orderId
     * @param float $amount
     * @param array $customData
     * @return array
     */
    public function getData(int $quoteId, $orderId, $amount, array $customData = []) : array
    {
        $quote = $this->quoteRepository->get($quoteId);

        $store = $this->storeManager->getStore();
        $currencyCode = $store instanceof Store ? $store->getCurrentCurrencyCode() : $quote->getCurrency()->getStoreCurrencyCode();

        $payload = [
            'transaction' => [
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => strtolower($currencyCode),
                'capture' => true,
            ],
            'redirect_url' => $this->urlBuilder->getUrl($this->hostedConfig->getRedirectUrl()),
            'webhook_url' => $this->urlBuilder->getUrl($this->hostedConfig->getWebhookUrl()),
            'payment' => [
                'reference' => $orderId
            ],
            'expires_in' => 3600
        ];

        if($this->hostedConfig->isBankOnly()) {
            $payload['payment_methods'] = ['pay_by_bank'];
        }

        if (isset($customData, $customData['custom1'])) {
            $payload['transaction']['custom1'] = $customData['custom1'];
        }
        if (isset($customData, $customData['custom2'])) {
            $payload['transaction']['custom2'] = $customData['custom2'];
        }

        $payload['customer'] = [];

        if (isset($customData, $customData['customer_id'])) {
            $acquiredCustomer = $this->createAcquiredCustomer->execute($customData['customer_id']);
            if ($acquiredCustomer && isset($acquiredCustomer['customer_id'])) {
                $payload['customer']['customer_id'] = $acquiredCustomer['customer_id'];
            }
        }

        /**
         * @var TransactionAddressDataInterface $addressData
         */
        $addressData = $this->getTransactionAddressData->execute($quote);
        $payload['customer']['billing'] = $addressData->getBilling();
        $payload['customer']['shipping'] = $addressData->getShipping() ?? [];

        return $payload;
    }
}
