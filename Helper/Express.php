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

namespace Acquired\Payments\Helper;

use Exception;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Framework\App\State;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total as AddressTotal;
use Magento\Backend\Model\Session\Quote as BackendModelSession;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Directory\Api\CountryInformationAcquirerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Directory\Model\RegionFactory;
use Acquired\Payments\Client\Payment as PaymentClient;
use Acquired\Payments\Exception\Payment\PaymentVerificationException;
use Acquired\Payments\Service\OrderPayment\Cancel as CancelOrderPayment;
use Acquired\Payments\Service\OrderPayment\Complete as CompleteOrderPayment;
use Acquired\Payments\Gateway\Config\Card\Config as CardConfig;
use Acquired\Payments\Model\Api\CreateAcquiredCustomer;

class Express
{
    const CONFIG_EXPRESS_ACTIVE = 'payment/acquired_express_payments/active';
    const CONFIG_EXPRESS_APPLEPAY_ACTIVE = 'payment/acquired_express_payments/applepay_active';

    /**
     * @param PaymentClient $paymentClient
     * @param LoggerInterface $logger
     * @param CheckoutSession $checkoutSession
     * @param BackendModelSession $backendQuoteSession
     * @param CartRepositoryInterface $cartRepository
     * @param GuestCartRepositoryInterface $guestCartRepository
     * @param ShippingMethodManagementInterface $shippingMethodManagement
     * @param State $state
     * @param UrlInterface $url
     * @param RequestInterface $request
     * @param ShippingInformationManagementInterface $shippingInformationManagement
     * @param ShippingInformationInterfaceFactory $shippingInformationFactory
     * @param CountryInformationAcquirerInterface $countryInformationAcquirer
     * @param CardConfig $cardConfig
     * @param CreateAcquiredCustomer $createAcquiredCustomer
     * @param CustomerSession $customerSession
     * @param PriceCurrencyInterface $priceCurrency
     * @param QuoteManagement $quoteManagement
     * @param ManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param RegionFactory $regionFactory
     * @param CompleteOrderPayment $completeOrderPayment
     * @param CancelOrderPayment $cancelOrderPayment
     */
    public function __construct(
        private readonly PaymentClient $paymentClient,
        private readonly LoggerInterface $logger,
        private readonly CheckoutSession $checkoutSession,
        private readonly BackendModelSession $backendQuoteSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly GuestCartRepositoryInterface $guestCartRepository,
        private readonly ShippingMethodManagementInterface $shippingMethodManagement,
        private readonly State $state,
        private readonly UrlInterface $url,
        private readonly RequestInterface $request,
        private readonly ShippingInformationManagementInterface $shippingInformationManagement,
        private readonly ShippingInformationInterfaceFactory $shippingInformationFactory,
        private readonly CountryInformationAcquirerInterface $countryInformationAcquirer,
        private readonly CardConfig $cardConfig,
        private readonly CreateAcquiredCustomer $createAcquiredCustomer,
        private readonly CustomerSession $customerSession,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly QuoteManagement $quoteManagement,
        private readonly ManagerInterface $eventManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RegionFactory $regionFactory,
        private readonly CompleteOrderPayment $completeOrderPayment,
        private readonly CancelOrderPayment $cancelOrderPayment
    ) {}

    /**
     * Check if the payment method is active for the current store
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isExpressMethodEnabled(string $method = ''): bool
    {
        if ($method === '') {
            return false;
        }

        $storeId = $this->storeManager->getStore()->getId();

        if (!(bool)$this->scopeConfig->getValue(self::CONFIG_EXPRESS_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }

        // We are doing map here so later we can add the rest of express payments
        $methodConfigMap = [
            'applepay' => self::CONFIG_EXPRESS_APPLEPAY_ACTIVE,
        ];

        if (!isset($methodConfigMap[$method])) {
            return false;
        }

        return (bool)$this->scopeConfig->getValue($methodConfigMap[$method], ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Place express order
     * @param array $params
     */
    public function placeOrder(array $params)
    {
        $quote = $this->checkoutSession->getQuote();

        if (!$quote || !$quote->getId()) {
            return [['error' => true, 'message' => 'No active quote']];
        }

        $shippingAddress = $quote->getShippingAddress();
        $this->updateAddress($shippingAddress, $params['shippingAddress'], $params['shippingAddress']['phoneNumber']);
        $this->updateAddress($quote->getBillingAddress(), $params['billingAddress'], $params['shippingAddress']['phoneNumber']);

        if (!empty($params['shippingMethod']['identifier'])) {
            $shippingAddress->setShippingMethod(
                str_replace(
                    '__SPLIT__',
                    '_',
                    $params['shippingMethod']['identifier']
                )
            );
        }

        // Apple Pay doesn't fire onshippingmethodselected when the shopper keeps the pre-selected
        // method, so the rates may never have been saved on the quote. Rates are collected through
        // collectTotals so the address item qty is set first; calling collectShippingRates directly
        // counts configurable parent and child items twice and doubles per-item rates.
        $shippingAddress->setCollectShippingRates(true);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        if (!$shippingAddress->getShippingMethod()
            || !$shippingAddress->getShippingRateByCode($shippingAddress->getShippingMethod())
        ) {
            $rates = $shippingAddress->getAllShippingRates();
            if (empty($rates)) {
                return [['error' => true, 'message' => 'No shipping methods are available for this address.']];
            }
            $shippingAddress->setShippingMethod(reset($rates)->getCode());
        }

        if (!$quote->getCustomerId()) {
            $email = $params['shippingAddress']['emailAddress'] ?? ($params['billingAddress']['emailAddress'] ?? null);
            $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST)
                  ->setCustomerEmail($email)
                  ->setCustomerFirstname($params['billingAddress']['givenName'] ?? null)
                  ->setCustomerLastname($params['billingAddress']['familyName'] ?? null)
                  ->setCustomerIsGuest(true)
                  ->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
        } else {
            $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);
        }

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
        }

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        // The order is created before the payment is taken, so a payment can never exist without its order
        try {
            $order = $this->submitOrder($quote);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            return [['error' => true, 'message' => 'Your order could not be created and you have not been charged. Please try again or proceed to regular checkout.']];
        }

        $applePayToken = json_encode($params['applePayPaymentToken']['paymentData']);

        // It can happen that sometimes , is passed as amount
        $amount = (string)$this->priceCurrency->roundPrice($order->getGrandTotal());
        $amount = preg_replace('/\s+/', '', $amount);
        $amount = preg_replace('/[^0-9.]/', '', $amount);

        $payload = [
            'transaction' => [
                'order_id' => $order->getIncrementId(),
                'amount'   => $amount,
                'currency' => strtolower((string)$order->getOrderCurrencyCode()),
                'capture'  => $this->cardConfig->getCaptureAction()
            ],
            'payment' => [
                'token'        => base64_encode($applePayToken),
                'scheme'       => strtolower($params['applePayPaymentToken']['paymentMethod']['network'] ?? ''),
                'type'         => strtolower($params['applePayPaymentToken']['paymentMethod']['type'] ?? ''),
                'display_name' => $params['applePayPaymentToken']['paymentMethod']['displayName'] ?? '',
                'create_card'  => false
            ]
        ];

        if ($quote->getCustomerId()) {
            $acquiredCustomer = $this->createAcquiredCustomer->execute($quote->getCustomerId());
            if ($acquiredCustomer) {
                $payload['customer']['customer_id'] = $acquiredCustomer['customer_id'];
            }
        }

        try {
            $apiResult = $this->paymentClient->process($payload, 'apple_pay');
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->cancelOrder($order, 'Apple Pay payment request failed.');
            return [['error' => true, 'message' => 'Unexpected error during payment. Please try again or proceed to regular checkout.']];
        }

        if (!$apiResult || !isset($apiResult['status']) || !in_array($apiResult['status'], ['success','settled','executed'], true)) {
            $this->cancelOrder($order, 'Apple Pay payment declined.');
            return [['error' => true, 'message' => 'Transaction declined. Please try again or proceed to regular checkout.']];
        }

        $transactionId = (string)($apiResult['transaction_id'] ?? '');

        try {
            $this->completeOrderPayment->execute($order, $transactionId);
        } catch (PaymentVerificationException $e) {
            $this->logger->critical($e->getMessage());
            $this->cancelOrder(
                $order,
                (string)__('Apple Pay payment could not be verified: %1', $e->getMessage()),
                $e->isPaymentTaken() ? $transactionId : null
            );
            return [['error' => true, 'message' => 'Your payment could not be confirmed and has been cancelled. Please try again or proceed to regular checkout.']];
        } catch (\Throwable $e) {
            // Acquired took the payment, so the order stays in payment review for the webhook to complete
            $this->logger->critical(
                __('Apple Pay order %1 was paid but could not be completed: %2', $order->getIncrementId(), $e->getMessage()),
                ['exception' => $e, 'transaction_id' => $transactionId]
            );
        }

        return [[ 'url' => $this->url->getUrl('checkout/onepage/success') ]];
    }

    /**
     * Create the order in payment review, before any payment is taken
     *
     * @param CartInterface $quote
     * @return OrderInterface
     * @throws LocalizedException
     */
    public function submitOrder(CartInterface $quote): OrderInterface
    {
        $quote->getPayment()->importData([
            'method' => 'acquired_payments_express',
            'additional_data' => [
                'payment_location' => 'mini-basket'
            ]
        ]);

        $order = $this->quoteManagement->submit($quote);

        if (!$order) {
            throw new LocalizedException(__('Apple Pay order was not created for quote %1', $quote->getId()));
        }

        $this->checkoutSession
            ->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderId($order->getId())
            ->setLastOrderStatus($order->getStatus());

        try {
            $this->eventManager->dispatch(
                'checkout_type_onepage_save_order_after',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );

            $this->eventManager->dispatch(
                'checkout_submit_all_after',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );

            $quote->setIsActive(false);
            $this->cartRepository->save($quote);
        } catch (\Throwable $e) {
            $this->logger->critical(
                __('Apple Pay order %1 created but post-order processing failed: %2', $order->getIncrementId(), $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $order;
    }

    /**
     * Cancel an Apple Pay order whose payment failed and give the shopper their cart back
     *
     * @param OrderInterface $order
     * @param string $reason
     * @param string|null $voidTransactionId
     * @return void
     */
    private function cancelOrder(OrderInterface $order, string $reason, ?string $voidTransactionId = null): void
    {
        try {
            $this->cancelOrderPayment->execute($order, $reason, true, $voidTransactionId);
        } catch (\Throwable $e) {
            $this->logger->critical(
                __('Apple Pay order %1 could not be cancelled: %2', $order->getIncrementId(), $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * Retrieve the cart instance.
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return CartInterface
     */
    public function getCart(): CartInterface
    {
        return $this->checkoutSession->getQuote();
    }

    /**
     * Update the address details for a given address object
     *
     * @param AddressInterface $address
     * @param array $input
     * @param string $telephone
     */
    public function updateAddress(AddressInterface $address, array $input, string $telephone = '')
    {
        $address->addData([
            AddressInterface::KEY_STREET => implode(PHP_EOL, $input['addressLines']),
            AddressInterface::KEY_COUNTRY_ID => $input['countryCode'],
            AddressInterface::KEY_LASTNAME => $input['familyName'],
            AddressInterface::KEY_FIRSTNAME => $input['givenName'],
            AddressInterface::KEY_CITY => $input['locality'],
            AddressInterface::KEY_POSTCODE => $input['postalCode'],
            AddressInterface::KEY_TELEPHONE => $telephone
        ]);

        // try to set regionId if exists
        if (array_key_exists('administrativeArea', $input)) {
            try {
                $data = $this->countryInformationAcquirer->getCountryInfo($input['countryCode']);
            } catch (NoSuchEntityException $exception) {
                $address->setRegion($input['administrativeArea']);
            }

            $regions = $data->getAvailableRegions();
            if ($regions === null) {
                $address->setRegion($input['administrativeArea']);
            } else {
                foreach ($regions as $region) {
                    if ($region->getCode() === $input['administrativeArea']) {
                        $address->setRegionId($region->getId());
                        break;
                    }
                }
            }
        }
    }

    /**
     * Fetch region ID by country code and region code
     *
     * @param string $countryCode
     * @param string $regionCode
     * @return int|null
     */
    public function getRegionIdByCode($countryCode, $regionCode)
    {
        $region = $this->regionFactory->create()->loadByCode($regionCode, $countryCode);
        return $region->getId() ?: null;
    }

    /**
     * Get the current store name without spaces
     *
     * @return string
     */
    public function getStoreName()
    {
        $storeName = $this->storeManager->getStore()->getName();
        return str_replace(' ', '', $storeName);
    }
}