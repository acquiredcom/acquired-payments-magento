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

namespace Acquired\Payments\Cron;

use Acquired\Payments\Service\OrderPayment\Cancel as CancelOrderPayment;
use Acquired\Payments\Ui\Method\CardProvider;
use Acquired\Payments\Ui\Method\PayByBankProvider;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;

class CancelPendingOrders
{
    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly FilterBuilder $filterBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly ManagerInterface $eventManager,
        private readonly CancelOrderPayment $cancelOrderPayment
    ) {
    }

    /**
     * Execute the cron
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->debug('Cron job CancelPendingOrders started.');

        $offset = 24 * 3600;
        $orders = $this->getValidOrders($offset);

        foreach ($orders as $order) {
            /** @var $order \Magento\Sales\Model\Order */
            if (time() - strtotime($order->getCreatedAt()) >= $offset) {
                $this->logger->debug('Cron job CancelPendingOrders cancelling order ' . $order->getIncrementId());

                try {
                    $this->cancelOrderPayment->execute($order, 'Order cancelled due to payment review timeout.');
                } catch (\Throwable $e) {
                    $this->logger->critical(
                        'Cron job CancelPendingOrders could not cancel order ' . $order->getIncrementId() . ': ' . $e->getMessage()
                    );
                }
            } else {
                $this->logger->debug('Cron job CancelPendingOrders skipping order ' . strtotime($order->getCreatedAt()) - time() . " | " . $order->getIncrementId());
            }
        }

        $this->logger->debug('Cron job CancelPendingOrders executed.');
    }

    protected function getValidOrders($timeOffset = 86400)
    {

        $methodFilter = $this->filterBuilder->setField('extension_attribute_payment_method.method')
            ->setConditionType('in')
            ->setValue([PayByBankProvider::CODE, CardProvider::CODE, 'acquired_payments_express'])
            ->create();

        $statusFilter = $this->filterBuilder->setField('status')
            ->setConditionType('eq')
            ->setValue('payment_review')
            ->create();

        $searchCriteriaBuilder = $this->searchCriteriaBuilderFactory->create();

        $searchCriteria = $searchCriteriaBuilder
            ->addFilters([$methodFilter])
            ->addFilters([$statusFilter])
            ->setPageSize(50)->setCurrentPage(1)->create();

        $ordersList = $this->orderRepository->getList($searchCriteria);

        $ordersList->getSelect()->where(
            new \Zend_Db_Expr('TIME_TO_SEC(TIMEDIFF(CURRENT_TIMESTAMP, `created_at`)) >= ' . $timeOffset)
        );

        return $ordersList;
    }
}
