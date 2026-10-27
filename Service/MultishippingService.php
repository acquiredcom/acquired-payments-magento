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

use Acquired\Payments\Api\Data\MultishippingInterface;
use Acquired\Payments\Api\Data\MultishippingResultInterface;
use Acquired\Payments\Api\Data\MultishippingResultInterfaceFactory;
use Acquired\Payments\Model\MultishippingRepository;
use Acquired\Payments\Model\MultishippingFactory;
use Acquired\Payments\Ui\Method\PayByBankProvider;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class MultishippingService
{

    const MULTISHIPPING_ORDER_ID_SUFFIX = '-ACQM';

    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly MultishippingFactory $multishippingFactory,
        private readonly MultishippingRepository $multishippingRepository,
        private readonly MultishippingResultInterfaceFactory $multishippingResultFactory,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilder $filterBuilder,
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    /**
     * Get multishipping by address id
     * @param int $addressId
     * @return MultishippingInterface|null
     */
    public function getMultishippingByAddressId(int $addressId): ?MultishippingInterface
    {
        // Get multishipping by transaction id
        $filter = $this->filterBuilder
            ->setField('quote_address_id')
            ->setValue($addressId)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter($filter)
            ->create();

        $multishippingResults = $this->multishippingRepository->getList($searchCriteria);

        return count($multishippingResults->getItems()) ? $multishippingResults->getItems()[0] : null;
    }

    /**
     * Get multishipping by transaction id
     * @param string $transactionId
     * @return array
     */
    public function getMultishippingByTransactionId(string $transactionId): array
    {
        // Get multishipping by transaction id
        $filter = $this->filterBuilder
            ->setField('acquired_transaction_id')
            ->setValue($transactionId)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter($filter)
            ->create();

        $multishippingResults = $this->multishippingRepository->getList($searchCriteria);
        return $multishippingResults->getItems();
    }

    /**
     * Get multishipping by transaction id
     * @param string $transactionId
     * @return MultishippingInterface|null
     */
    public function getMultishippingByReservedOrderId(string $transactionId): ?MultishippingInterface
    {
        // Get multishipping by transaction id
        $filter = $this->filterBuilder
            ->setField('quote_reserved_id')
            ->setValue($transactionId)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter($filter)
            ->create();

        $multishippingResults = $this->multishippingRepository->getList($searchCriteria);

        return count($multishippingResults->getItems()) ? $multishippingResults->getItems()[0] : null;
    }

    /**
     * Get multishipping by session id
     * @param string $sessionId
     * @return array
     */
    public function getMultishippingBySessionId(string $sessionId): array
    {
        $filter = $this->filterBuilder
            ->setField('acquired_session_id')
            ->setValue($sessionId)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter($filter)
            ->create();

        $multishippingResults = $this->multishippingRepository->getList($searchCriteria);

        return $multishippingResults->getItems();
    }

    /**
     * Get multishipping by order id
     * @param int $orderId
     * @return array
     */
    public function getMultishippingByOrderId(int $orderId): array
    {
        $filter = $this->filterBuilder
            ->setField('order_id')
            ->setValue($orderId)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter($filter)
            ->create();

        $multishippingResults = $this->multishippingRepository->getList($searchCriteria);

        return $multishippingResults->getItems();
    }

    /**
     * Get multishipping by order
     * @param $order
     * @return MultishippingInterface|null
     */
    public function getMultishippingByOrder($order): ?MultishippingInterface
    {
        $address = $order->getIsVirtual() ? $order->getBillingAddress() : $order->getShippingAddress();
        $multishippingResult = $this->getMultishippingByAddressId((int) $address->getQuoteAddressId());

        return $multishippingResult;
    }

    /**
     * Get reserved ids by transaction id
     * @param string $orderId
     * @return array
     */
    public function getReservedIdsByTransactionId(string $orderId): array
    {
        $multishippingItems = $this->getMultishippingByTransactionId($orderId);

        $reservedIds = [];

        foreach ($multishippingItems as $multishippingItem) {
            $reservedIds[] = $multishippingItem->getQuoteReservedId();
        }

        return $reservedIds;
    }

    /**
     * Create multishipping reservations
     * @param Quote $quote
     * @param string|null $sessionId
     * @param string|null $transactionId
     * @return array
     */
    public function reserveOrderIds(Quote $quote, ?string $sessionId = null, ?string $transactionId = null): array
    {
        $reservedIds = [];
        if ($quote->getIsMultiShipping()) {

            $shippingAddresses = $quote->getAllShippingAddresses();
            if ($quote->hasVirtualItems()) {
                $shippingAddresses[] = $quote->getBillingAddress();
            }

            foreach ($shippingAddresses as $address) {
                if ($address->getAddressType() == 'shipping') {
                    $multishipping = $this->getMultishippingByAddressId((int) $address->getId());
                    if (!$multishipping) {
                        $multishipping = $this->multishippingFactory->create();
                    }
                    if (!$multishipping->getQuoteReservedId()) {
                        $quote->setReservedOrderId(null);
                        $quote->reserveOrderId();
                        $multishipping->setQuoteReservedId($quote->getReservedOrderId());
                        $quote->setReservedOrderId(null);
                    }

                    $multishipping->setCustomerId((int) $quote->getCustomerId());
                    $multishipping->setQuoteAddressId((int) $address->getId());

                    if ($sessionId) {
                        $multishipping->setAcquiredSessionId($sessionId);
                    }
                    if ($transactionId) {
                        $multishipping->setAcquiredTransactionId($transactionId);
                        $multishipping->setStatus('processing');
                    }

                    $this->multishippingRepository->save($multishipping);

                    $reservedIds[] = $multishipping->getQuoteReservedId();
                }
            }
        }

        $quote->setReservedOrderId(null);
        $quote->save();

        return $reservedIds;
    }

    /**
     * Process multishipping orders by candidate order ids and returns a data object with needed information
     * @param array $candidateOrderIds
     * @return MultishippingResultInterface
     */
    public function processMultishippingOrdersByIds(array $candidateOrderIds): MultishippingResultInterface
    {
        if (empty($candidateOrderIds)) {
            return $this->processMultishippingOrders([]);
        }

        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToSelect("*");
        $orderCollection->addFieldToFilter('entity_id', ['in' => $candidateOrderIds]);

        return $this->processMultishippingOrders($orderCollection->getItems());
    }

    /**
     * In case a creation of a previous multishipping order failed, we need to clear the multishipping data so new session can be initialized
     *
     * @param Quote $quote
     * @return void
     */
    public function clearMultishippingData(Quote $quote) {
        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems()) {
            $shippingAddresses[] = $quote->getBillingAddress();
        }

        foreach ($shippingAddresses as $address) {
            $multishipping = $this->getMultishippingByAddressId((int) $address->getId());
            if($multishipping) {
                $this->multishippingRepository->delete($multishipping);
            }
        }
    }

    /**
     * Process multishipping orders by candidate order increment ids and returns a data object with needed information
     * @param array $candidateOrderIds
     * @return MultishippingResultInterface
     */
    public function processMultishippingOrdersByIncrementIds(array $candidateOrderIds): MultishippingResultInterface
    {
        if (empty($candidateOrderIds)) {
            return $this->processMultishippingOrders([]);
        }

        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToSelect("*");
        $orderCollection->addFieldToFilter('increment_id', ['in' => $candidateOrderIds]);

        return $this->processMultishippingOrders($orderCollection->getItems());
    }

    /**
     * Process multishipping orders
     * @param array $orders
     * @return MultishippingResultInterface
     */
    protected function processMultishippingOrders($orders): MultishippingResultInterface
    {
        $customerId = null;
        $multishippingOrderId = null;
        $resultOrders = [];
        $amount = 0;

        foreach ($orders as $order) {
            if ($order->getPayment()->getMethod() == PayByBankProvider::CODE && $order->getState() == Order::STATE_PAYMENT_REVIEW) {

                // check if multishipping is processed already
                $multishippingItem = $this->getMultishippingByReservedOrderId($order->getIncrementId());

                if ($multishippingItem->getStatus() == MultishippingInterface::STATUS_SUCCESS) {
                    continue;
                }

                $multishippingItem->setOrderId($order->getId());
                $multishippingItem->save();

                if (!$multishippingOrderId) {
                    $multishippingOrderId = $order->getIncrementId() . self::MULTISHIPPING_ORDER_ID_SUFFIX;
                }
                if (!$customerId && $order->getCustomerId()) {
                    $customerId = (int) $order->getCustomerId();
                }
                $resultOrders[] = $order;
                $amount += $order->getGrandTotal();
            }
        }

        $result = $this->multishippingResultFactory->create();
        if($multishippingOrderId) {
            $result->setMultishippingOrderId($multishippingOrderId);
        }
        if($customerId) {
            $result->setCustomerId($customerId);
        }
        $result->setCandidateOrders($orders);
        $result->setOrders($resultOrders);
        $result->setAmount($amount);

        return $result;
    }
}
