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

namespace Acquired\Payments\Controller\Applepay;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Directory\Model\RegionFactory;

class Shipping extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private JsonSerializer $json,
        private CartRepositoryInterface $cartRepository,
        private ShippingInformationManagementInterface $shippingInfoMgmt,
        private ShippingMethodManagementInterface $shippingMethodMgmt,
        private \Magento\Checkout\Model\Session $checkoutSession,
        private ShippingInformationInterfaceFactory $shippingInformationFactory,
        private RegionFactory $regionFactory,
    ) {
        parent::__construct($context);
    }
    
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return $result->setData(['error' => true, 'message' => 'No active quote']);
        }

        $raw = $this->getRequest()->getContent();
        $params = $raw ? $this->json->unserialize($raw) : [];
        
        $address = $quote->getShippingAddress();
        $address->setData(null);
        $address->setCountryId(strtoupper($params['countryCode']));
        $address->setPostcode($params['postalCode']);
        
        if (!empty($params['city'])) {
            $address->setCity($params['city']);
        }
        
        if (!empty($params['countryState'])) {
            $region = $this->regionFactory->create()->loadByCode($params['countryState'], $address->getCountryId());
            if ($region->getId()) {
                $address->setRegionId($region->getId());
            } else {
                $address->setRegion($params['countryState']);
            }
        }
        
        // This is second call from applepay where we actually have shipping method set
        if (!empty($params['shippingMethod']['identifier'])) {
            $this->updateShippingAddress((int)$quote->getId(), $address, $params['shippingMethod']['identifier']);
        }

        $quote->setPaymentMethod('acquired_payments_express');
        $quote->getPayment()->importData(['method' => 'acquired_payments_express']);
        
        $this->cartRepository->save($quote);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        
        $methods = $this->shippingMethodMgmt->getList((int)$quote->getId());

        // Apple Pay preselects the first method and won't call back if the shopper keeps it,
        // so the totals shown on the sheet must already include it.
        if (empty($params['shippingMethod']['identifier']) && !empty($methods)) {
            $first = reset($methods);
            $this->updateShippingAddress(
                (int)$quote->getId(),
                $address,
                $first->getCarrierCode() . '__SPLIT__' . $first->getMethodCode()
            );
            $quote->setTotalsCollectedFlag(false)->collectTotals();
        }
        $payload = [
            'shipping_methods' => array_map(function ($m) {
                return [
                    'identifier' => $m->getCarrierCode() . '__SPLIT__' . $m->getMethodCode(),
                    'label'      => $m->getMethodTitle() . ' - ' . $m->getCarrierTitle(),
                    'amount'     => number_format((float)($m->getPriceInclTax() ?: 0.0), 2, '.', ''),
                    'detail'     => '',
                ];
            }, $methods),
            'totals' => array_map(function (\Magento\Quote\Model\Quote\Address\Total $t) {
                return [
                    'type'   => 'final',
                    'code'   => $t->getCode(),
                    'label'  => $t->getData('title'),
                    'amount' => number_format((float)$t->getData('value'), 2, '.', ''),
                ];
            }, array_values($quote->getTotals()))
        ];

        return $result->setData($payload);
    }

    private function updateShippingAddress(int $quoteId, AddressInterface $address, string $identifier)
    {
        $address->setCollectShippingRates(true);
        $address->setShippingMethod(str_replace('__SPLIT__', '_', $identifier));
        
        [$carrierCode, $methodCode] = explode('__SPLIT__', $identifier);
        $shippingInformation = $this->shippingInformationFactory->create([
            'data' => [
                ShippingInformationInterface::SHIPPING_ADDRESS => $address,
                ShippingInformationInterface::SHIPPING_CARRIER_CODE => $carrierCode,
                ShippingInformationInterface::SHIPPING_METHOD_CODE => $methodCode,
            ],
        ]);
        
        $this->shippingInfoMgmt->saveAddressInformation($quoteId, $shippingInformation);
    }
}