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

use Acquired\Payments\Exception\Webhook\WebhookIntegrityException;
use Acquired\Payments\Exception\Webhook\WebhookOrderNotFoundException;
use Acquired\Payments\Exception\Webhook\WebhookVersionException;
use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Model\Webhook\Context as WebhookContext;
use Acquired\Payments\Model\Webhook\Processor\OrderStatusUpdateProcessor;
use Acquired\Payments\Service\InvoicePaidOrder;
use Acquired\Payments\Service\MultishippingService;
use Acquired\Payments\Ui\Method\PayByBankProvider;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
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
    private const API_SECRET = 'app-key';
    private const SERIALIZED = '{"webhook_type":"status_update"}';
    private const TRANSACTION_ID = 'a7e3fde5-5b83-44f4-9915-782bc7121717';

    private $basicConfig;
    private $serializer;
    private $orderRepository;
    private $searchCriteriaBuilder;
    private $invoicePaidOrder;
    private $orderManagement;
    private $lockAvailable = true;
    private $processor;

    protected function setUp(): void
    {
        $this->basicConfig = $this->createMock(Basic::class);
        $this->basicConfig->method('getApiSecret')->willReturn(self::API_SECRET);

        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->serializer->method('serialize')->willReturn(self::SERIALIZED);

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $this->invoicePaidOrder = $this->createMock(InvoicePaidOrder::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);

        $webhookContext = new WebhookContext(
            $this->basicConfig,
            $this->serializer,
            $this->orderRepository,
            $this->orderManagement,
            $this->searchCriteriaBuilder,
            $this->createMock(InvoiceService::class),
            $this->createMock(Transaction::class),
            $this->createMock(InvoiceSender::class),
            $this->createMock(OrderSender::class),
            $this->createMock(MultishippingService::class),
            $this->createMock(OrderCollectionFactory::class),
            $this->invoicePaidOrder,
            $this->createLockManager()
        );

        $this->processor = new OrderStatusUpdateProcessor($this->basicConfig, $this->serializer, $webhookContext);
    }

    public function testValidHashInvoicesPaidOrder()
    {
        $this->givenOrders([$this->createOrder()]);

        $this->invoicePaidOrder->expects($this->once())
            ->method('execute')
            ->with(7, self::TRANSACTION_ID)
            ->willReturn(['success' => true, 'message' => 'Order #000000007 invoiced successfully.']);

        $result = $this->processor->execute($this->webhookData('success'), $this->validHash(), '2');

        $this->assertTrue($result['success']);
    }

    public function testRetrySuffixedOrderIdIsResolved()
    {
        $this->givenOrders([$this->createOrder()]);
        $this->searchCriteriaBuilder->expects($this->once())
            ->method('addFilter')
            ->with('increment_id', '000000007')
            ->willReturnSelf();
        $this->invoicePaidOrder->method('execute')->willReturn(['success' => true, 'message' => '']);

        $this->processor->execute($this->webhookData('success', '000000007-ACQR-1700000000'), $this->validHash(), '2');
    }

    public function testInvalidHashIsRejected()
    {
        $this->invoicePaidOrder->expects($this->never())->method('execute');
        $this->expectException(WebhookIntegrityException::class);

        $this->processor->execute($this->webhookData('success'), 'not-the-hash', '2');
    }

    public function testVersionMismatchIsRejected()
    {
        $this->invoicePaidOrder->expects($this->never())->method('execute');
        $this->expectException(WebhookVersionException::class);

        $this->processor->execute($this->webhookData('success'), $this->validHash(), '1');
    }

    public function testUnknownOrderThrowsNotFound()
    {
        $this->givenOrders([]);
        $this->expectException(WebhookOrderNotFoundException::class);

        $this->processor->execute($this->webhookData('success'), $this->validHash(), '2');
    }

    public function testDuplicateSuccessWebhookDoesNotInvoiceTwice()
    {
        $this->givenOrders([$this->createOrder()]);

        $this->invoicePaidOrder->expects($this->exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                ['success' => true, 'message' => 'Order #000000007 invoiced successfully.'],
                ['success' => false, 'message' => 'Order #000000007 is not awaiting payment.']
            );

        $first = $this->processor->execute($this->webhookData('success'), $this->validHash(), '2');
        $second = $this->processor->execute($this->webhookData('success'), $this->validHash(), '2');

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
    }

    public function testCancelledPaymentCancelsOrder()
    {
        $this->givenOrders([$this->createOrder()]);
        $this->orderManagement->expects($this->once())->method('cancel')->with(7)->willReturn(true);

        $result = $this->processor->execute($this->webhookData('cancelled'), $this->validHash(), '2');

        $this->assertTrue($result['success']);
    }

    public function testOrderThatCannotBeCancelledIsAcknowledged()
    {
        $this->givenOrders([$this->createOrder()]);
        $this->orderManagement->method('cancel')->willReturn(false);

        $result = $this->processor->execute($this->webhookData('cancelled'), $this->validHash(), '2');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function testCancellationWaitsForInvoicingLock()
    {
        $this->lockAvailable = false;
        $this->givenOrders([$this->createOrder()]);
        $this->orderManagement->expects($this->never())->method('cancel');

        $result = $this->processor->execute($this->webhookData('cancelled'), $this->validHash(), '2');

        $this->assertTrue($result['retryable']);
    }

    public function testCancellationFailureIsRetryable()
    {
        $this->givenOrders([$this->createOrder()]);
        $this->orderManagement->method('cancel')
            ->willThrowException(new LocalizedException(__('Could not void payment')));

        $result = $this->processor->execute($this->webhookData('cancelled'), $this->validHash(), '2');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    private function createLockManager(): LockManagerInterface
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(fn () => $this->lockAvailable);

        return $lockManager;
    }

    private function webhookData(string $status, string $orderId = '000000007'): array
    {
        return [
            'webhook_type' => 'status_update',
            'webhook_body' => [
                'transaction_id' => self::TRANSACTION_ID,
                'status' => $status,
                'order_id' => $orderId
            ]
        ];
    }

    private function validHash(): string
    {
        return hash_hmac('sha256', self::SERIALIZED, self::API_SECRET);
    }

    private function createOrder(): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(PayByBankProvider::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function givenOrders(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }
}
