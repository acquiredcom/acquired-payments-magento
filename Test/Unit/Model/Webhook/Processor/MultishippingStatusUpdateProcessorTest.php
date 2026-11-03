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

namespace Acquired\Payments\Test\Unit\Model\Webhook\Processor;

use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Model\Webhook\Context as WebhookContext;
use Acquired\Payments\Model\Webhook\Processor\MultishippingStatusUpdateProcessor;
use Acquired\Payments\Service\InvoicePaidOrder;
use Acquired\Payments\Service\MultishippingService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;

class MultishippingStatusUpdateProcessorTest extends TestCase
{
    private const TRANSACTION_ID = 'a7e3fde5-5b83-44f4-9915-782bc7121717';

    private $invoicePaidOrder;
    private $processor;

    protected function setUp(): void
    {
        $basicConfig = $this->createMock(Basic::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $this->invoicePaidOrder = $this->createMock(InvoicePaidOrder::class);

        $webhookContext = new WebhookContext(
            $basicConfig,
            $serializer,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(Transaction::class),
            $this->createMock(InvoiceSender::class),
            $this->createMock(OrderSender::class),
            $this->createMock(MultishippingService::class),
            $this->createMock(OrderCollectionFactory::class),
            $this->invoicePaidOrder,
            $this->createMock(LockManagerInterface::class)
        );

        $this->processor = new MultishippingStatusUpdateProcessor($basicConfig, $serializer, $webhookContext);
    }

    public function testEachOrderIsInvoicedAsMultishipping()
    {
        $this->invoicePaidOrder->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (int $orderId, string $transactionId, bool $isMultishipping) {
                $this->assertSame(self::TRANSACTION_ID, $transactionId);
                $this->assertTrue($isMultishipping);

                return ['success' => true, 'message' => '', 'retryable' => false];
            });

        $result = $this->processor->bulkInvoiceOrders([$this->createOrder(7), $this->createOrder(8)], self::TRANSACTION_ID);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function testRetryableWhenAnyOrderNeedsRetry()
    {
        $this->invoicePaidOrder->method('execute')->willReturnOnConsecutiveCalls(
            ['success' => true, 'message' => '', 'retryable' => false],
            ['success' => false, 'message' => '', 'retryable' => true]
        );

        $result = $this->processor->bulkInvoiceOrders([$this->createOrder(7), $this->createOrder(8)], self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    private function createOrder(int $entityId): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn(sprintf('%09d', $entityId));

        return $order;
    }
}
