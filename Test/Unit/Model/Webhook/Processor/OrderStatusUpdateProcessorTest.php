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
use Acquired\Payments\Model\Webhook\Processor\OrderStatusUpdateProcessor;
use Acquired\Payments\Service\MultishippingService;
use Acquired\Payments\Service\OrderPayment\Cancel;
use Acquired\Payments\Service\OrderPayment\Complete;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;

class OrderStatusUpdateProcessorTest extends TestCase
{
    private $order;
    private $complete;
    private $cancel;
    private $processor;

    protected function setUp(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnMap([['order_first', '1']]);

        $this->order = $this->createMock(Order::class);
        $this->order->method('getPayment')->willReturn($payment);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$this->order]);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($searchResult);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->complete = $this->createMock(Complete::class);
        $this->cancel = $this->createMock(Cancel::class);

        $context = new WebhookContext(
            $this->createMock(Basic::class),
            $this->createMock(SerializerInterface::class),
            $orderRepository,
            $this->createMock(OrderManagementInterface::class),
            $searchCriteriaBuilder,
            $this->createMock(InvoiceService::class),
            $this->createMock(Transaction::class),
            $this->createMock(InvoiceSender::class),
            $this->createMock(OrderSender::class),
            $this->createMock(MultishippingService::class),
            $this->createMock(OrderCollectionFactory::class),
            $this->complete,
            $this->cancel
        );

        $this->processor = new OrderStatusUpdateProcessor(
            $this->createMock(Basic::class),
            $this->createMock(SerializerInterface::class),
            $context
        );
    }

    public function testSuccessCompletesOrderInPaymentReview()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);

        $this->complete->expects($this->once())->method('execute')->with($this->order, 'txn-1');
        $this->cancel->expects($this->never())->method('releasePayment');

        $result = $this->processor->process($this->getWebhook('success'), '', '2');
        $this->assertTrue($result['success']);
    }

    public function testSuccessForCancelledOrderReleasesPayment()
    {
        $this->order->method('getState')->willReturn(Order::STATE_CANCELED);

        $this->complete->expects($this->never())->method('execute');
        $this->cancel->expects($this->once())->method('releasePayment')->with($this->order, 'txn-1');

        $result = $this->processor->process($this->getWebhook('success'), '', '2');
        $this->assertTrue($result['success']);
    }

    public function testCancelledWebhookCancelsOrderInPaymentReview()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);

        $this->cancel->expects($this->once())->method('execute');

        $result = $this->processor->process($this->getWebhook('cancelled'), '', '2');
        $this->assertTrue($result['success']);
    }

    private function getWebhook(string $status): array
    {
        return [
            'webhook_body' => [
                'order_id' => '000000400',
                'transaction_id' => 'txn-1',
                'status' => $status
            ]
        ];
    }
}
