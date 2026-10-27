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
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Directory\Model\RegionFactory;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Model\ShippingAddressManagementInterface;
use Acquired\Payments\Client\Payment as PaymentClient;
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
     * @param InvoiceService $invoiceService
     * @param OrderRepositoryInterface $orderRepository
     * @param ManagerInterface $eventManager
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param InvoiceSender $invoiceSender
     * @param RegionFactory $regionFactory
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
        private readonly InvoiceService $invoiceService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ManagerInterface $eventManager,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly InvoiceSender $invoiceSender,
        private readonly RegionFactory $regionFactory,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly ShippingAddressManagementInterface $shippingAddressManagement
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

        if (!$quote->getCustomerId()) {
            $email = $params['shippingAddress']['emailAddress'] ?? ($params['billingAddress']['emailAddress'] ?? null);
            $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST)
                  ->setCustomerEmail($email)
                  ->setCustomerIsGuest(true)
                  ->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
        } else {
            $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);
        }

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
        }

        $quote->collectTotals();
        $this->cartRepository->save($quote);

        $applePayToken = json_encode($params['applePayPaymentToken']['paymentData']);

        // It can happen that sometimes , is passed as amount
        $amount = (string)$this->priceCurrency->roundPrice($quote->getGrandTotal());
        $amount = preg_replace('/\s+/', '', $amount);
        $amount = preg_replace('/[^0-9.]/', '', $amount);

        $payload = [
            'transaction' => [
                'order_id' => $quote->getReservedOrderId(),
                'amount'   => $amount,
                'currency' => strtolower($quote->getCurrency()->getStoreCurrencyCode()),
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

        try {
            $apiResult = $this->paymentClient->process($payload, 'apple_pay');
            if (!$apiResult || !isset($apiResult['status']) || !in_array($apiResult['status'], ['success','settled','executed'], true)) {
                return [['error' => true, 'message' => 'Transaction declined. Please try again or proceed to regular checkout.']];
            }

            $transactionId = $apiResult['transaction_id'] ?? null;
            $this->doPlaceOrder($transactionId, $quote);
        } catch (\Throwable $e) {
            // it could be that money is taken and order is not created so here we should force create an order in some state and note
            $this->logger->critical($e);
            return [['error' => true, 'message' => 'Unexpected error during payment. Please try again or proceed to regular checkout.']];
        }

        return [[ 'url' => $this->url->getUrl('checkout/onepage/success') ]];
    }

    /**
     * Real Place order
     *
     * @param string $transactionId
     * @param CartInterface $quote
     * @return void
     */
    public function doPlaceOrder(string $transactionId, CartInterface $quote): void
    {
        $quote->getPayment()->importData([
            'method' => 'acquired_payments_express',
            'additional_data' => [
                'transaction_id' => $transactionId,
                'payment_location' => 'mini-basket'
            ]
        ]);

        $order = $this->quoteManagement->submit($quote);

        if ($order) {

            $this->eventManager->dispatch(
                'checkout_type_onepage_save_order_after',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );

            $this->checkoutSession
                ->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderId($order->getId())
                ->setLastOrderStatus($order->getStatus());

            $this->eventManager->dispatch(
                'checkout_submit_all_after',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );

            // Create invoice if mode = capture
            if ($order->canInvoice() && $this->cardConfig->getCaptureAction()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);

                $invoice->setTransactionId($transactionId);
                $order->getPayment()->setLastTransId($transactionId);
                $order->getPayment()->setAdditionalInformation('transaction_id', $transactionId)
                    ->setAdditionalInformation('payment_location', 'mini-basket');

                $invoice->register();
                $invoice->pay();

                $this->invoiceRepository->save($invoice);

                // Update state and status
                $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
                $status = $order->getConfig()->getStateDefaultStatus($state);
                $order->setState($state)->addStatusToHistory(
                    $status,
                    "Apple Pay express payment successufully captured.",
                    false
                );

                $this->orderRepository->save($order);
                $this->invoiceSender->send($invoice);
            }

            $quote->setIsActive(false);
            $this->cartRepository->save($quote);
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