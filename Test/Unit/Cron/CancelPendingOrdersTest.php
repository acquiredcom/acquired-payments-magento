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

namespace Acquired\Payments\Test\Unit\Cron;

use Acquired\Payments\Api\Data\MultishippingInterface;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Client\Transaction;
use Acquired\Payments\Cron\CancelPendingOrders;
use Acquired\Payments\Exception\Api\ApiCallException;
use Acquired\Payments\Service\InvoicePaidOrder;
use Acquired\Payments\Service\MultishippingService;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CancelPendingOrdersTest extends TestCase
{
    private const ORDER_ID = 7;
    private const INCREMENT_ID = '000000007';
    private const RETRY_ID = '000000007-ACQR-1700000000';

    private $orderRepository;
    private $emulation;
    private $transactionClient;
    private $invoicePaidOrder;
    private $lockManager;
    private $lockAvailable = true;
    private $multishippingService;
    private $freshOrder;
    private $flagManager;
    private $filterValues = [];
    private $cron;

    protected function setUp(): void
    {
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        foreach (['addFilters', 'addSortOrder', 'setPageSize', 'setCurrentPage'] as $method) {
            $searchCriteriaBuilder->method($method)->willReturnSelf();
        }
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $searchCriteriaBuilderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $searchCriteriaBuilderFactory->method('create')->willReturn($searchCriteriaBuilder);

        $filterBuilder = $this->createMock(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnSelf();
        $filterBuilder->method('setConditionType')->willReturnSelf();
        $filterBuilder->method('setValue')->willReturnCallback(function ($value) use ($filterBuilder) {
            $this->filterValues[] = $value;
            return $filterBuilder;
        });
        $filterBuilder->method('create')->willReturn($this->createMock(Filter::class));

        $sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $sortOrderBuilder->method('setField')->willReturnSelf();
        $sortOrderBuilder->method('setDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->emulation = $this->createMock(Emulation::class);

        $this->transactionClient = $this->createMock(Transaction::class);
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('getTransaction')->willReturn($this->transactionClient);

        $this->invoicePaidOrder = $this->createMock(InvoicePaidOrder::class);
        $this->invoicePaidOrder->method('execute')->willReturn(['success' => true, 'message' => '', 'retryable' => false]);

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturnCallback(fn () => $this->lockAvailable);

        $this->freshOrder = $this->createMock(Order::class);
        $this->freshOrder->method('load')->willReturnSelf();
        $this->freshOrder->method('getIncrementId')->willReturn(self::INCREMENT_ID);
        $orderFactory = $this->createMock(OrderFactory::class);
        $orderFactory->method('create')->willReturn($this->freshOrder);

        $this->multishippingService = $this->createMock(MultishippingService::class);
        $this->flagManager = $this->createMock(FlagManager::class);

        $this->cron = new CancelPendingOrders(
            $this->createMock(LoggerInterface::class),
            $searchCriteriaBuilderFactory,
            $filterBuilder,
            $sortOrderBuilder,
            $this->orderRepository,
            $this->createMock(ManagerInterface::class),
            $this->emulation,
            $gateway,
            $this->invoicePaidOrder,
            $this->lockManager,
            $orderFactory,
            $this->multishippingService,
            $this->flagManager
        );
    }

    public function testPaidOrderIsInvoiced()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => [$this->transaction(self::INCREMENT_ID, 'success', 'txn-paid')]]);

        $this->invoicePaidOrder->expects($this->once())->method('execute')->with(self::ORDER_ID, 'txn-paid');
        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testOrderWithNoPaymentIsCancelled()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => []]);
        $this->givenFreshOrder(Order::STATE_PAYMENT_REVIEW, false);

        $this->invoicePaidOrder->expects($this->never())->method('execute');
        $this->freshOrder->expects($this->once())->method('registerCancellation');
        $this->orderRepository->expects($this->once())->method('save')->with($this->freshOrder);
        $this->lockManager->expects($this->once())->method('unlock')->with(InvoicePaidOrder::LOCK_PREFIX . self::ORDER_ID);

        $this->cron->execute();
    }

    public function testDeclinedAttemptsOnlyAreCancelled()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => [$this->transaction(self::INCREMENT_ID, 'declined', 'txn-1')]]);
        $this->givenFreshOrder(Order::STATE_PAYMENT_REVIEW, false);

        $this->freshOrder->expects($this->once())->method('registerCancellation');

        $this->cron->execute();
    }

    public function testOrderPaidOnRetryIsInvoiced()
    {
        $this->givenPendingOrder([self::INCREMENT_ID, self::RETRY_ID]);
        $this->givenTransactions([
            self::INCREMENT_ID => [$this->transaction(self::INCREMENT_ID, 'declined', 'txn-1')],
            self::RETRY_ID => [$this->transaction(self::RETRY_ID, 'success', 'txn-2')],
        ]);

        $this->invoicePaidOrder->expects($this->once())->method('execute')->with(self::ORDER_ID, 'txn-2');
        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testOrderWithoutTrackedIdsIsCheckedByIncrementId()
    {
        $this->givenPendingOrder(null);
        $this->transactionClient->expects($this->once())
            ->method('list')
            ->with([Transaction::ORDER_ID => self::INCREMENT_ID, Transaction::LIMIT => 100])
            ->willReturn($this->listResponse([$this->transaction(self::INCREMENT_ID, 'success', 'txn-paid')]));

        $this->invoicePaidOrder->expects($this->once())->method('execute')->with(self::ORDER_ID, 'txn-paid');

        $this->cron->execute();
    }

    public function testApiErrorLeavesOrderUntouched()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->transactionClient->method('list')->willThrowException(new ApiCallException(__('Timeout')));

        $this->invoicePaidOrder->expects($this->never())->method('execute');
        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testUnexpectedOrderIdInResponseLeavesOrderUntouched()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->transactionClient->method('list')
            ->willReturn($this->listResponse([$this->transaction('000000070', 'declined', 'txn-1')]));

        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testIncompleteResponseLeavesOrderUntouched()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->transactionClient->method('list')->willReturn(['meta' => ['total' => 101], 'data' => []]);

        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testMalformedResponseLeavesOrderUntouched()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->transactionClient->method('list')->willReturn(['error' => 'unexpected']);

        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testPaymentNotMatchingOrderTotalLeavesOrderUntouched()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => [$this->transaction(self::INCREMENT_ID, 'success', 'txn-paid', 1.00)]]);

        $this->invoicePaidOrder->expects($this->never())->method('execute');
        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testRefundIsNotTreatedAsPayment()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => [$this->transaction(self::INCREMENT_ID, 'success', 'txn-1', 25.50, 'refund')]]);
        $this->givenFreshOrder(Order::STATE_PAYMENT_REVIEW, false);

        $this->invoicePaidOrder->expects($this->never())->method('execute');
        $this->freshOrder->expects($this->once())->method('registerCancellation');

        $this->cron->execute();
    }

    public function testAlreadyInvoicedOrderIsNotCancelled()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => []]);
        $this->givenFreshOrder(Order::STATE_PROCESSING, true);

        $this->expectNoCancellation();
        $this->lockManager->expects($this->once())->method('unlock');

        $this->cron->execute();
    }

    public function testBusyLockLeavesOrderForNextRun()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => []]);
        $this->lockAvailable = false;

        $this->expectNoCancellation();

        $this->cron->execute();
    }

    public function testMultishippingOrderIsCancelledWithoutLookup()
    {
        $this->givenPendingOrder(null);
        $this->multishippingService->method('getMultishippingByReservedOrderId')
            ->willReturn($this->createMock(MultishippingInterface::class));
        $this->givenFreshOrder(Order::STATE_PAYMENT_REVIEW, false);

        $this->transactionClient->expects($this->never())->method('list');
        $this->freshOrder->expects($this->once())->method('registerCancellation');

        $this->cron->execute();
    }

    public function testEmulatesOrderStoreAndStopsOnError()
    {
        $this->givenPendingOrder([self::INCREMENT_ID], 3, new RuntimeException('boom'));

        $this->emulation->expects($this->once())->method('startEnvironmentEmulation')->with(3, 'frontend', true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->cron->execute();
    }

    public function testFullBatchMovesCursorPastLastOrder()
    {
        $this->flagManager->method('getFlagData')->willReturn(120);
        $orders = [];
        for ($id = 121; $id < 171; $id++) {
            $orders[$id] = $this->createSkippedOrder($id);
        }
        $this->givenOrderList($orders);

        $this->flagManager->expects($this->once())->method('saveFlag')->with('acquired_cancel_pending_orders_cursor', 170);

        $this->cron->execute();

        $this->assertContains(120, $this->filterValues);
    }

    public function testPartialBatchRestartsFromTheBeginning()
    {
        $this->givenPendingOrder([self::INCREMENT_ID]);
        $this->givenTransactions([self::INCREMENT_ID => [$this->transaction(self::INCREMENT_ID, 'success', 'txn-paid')]]);

        $this->flagManager->expects($this->once())->method('saveFlag')->with('acquired_cancel_pending_orders_cursor', 0);

        $this->cron->execute();
    }

    private function createSkippedOrder(int $id): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn(['00000' . $id]);
        $this->transactionClient->method('list')->willThrowException(new ApiCallException(__('Timeout')));

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn('00000' . $id);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function givenOrderList(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }

    private function givenPendingOrder(?array $acquiredOrderIds, int $storeId = 1, ?RuntimeException $error = null): void
    {
        $payment = $this->createMock(Payment::class);
        $additionalInformation = $payment->method('getAdditionalInformation')->with('acquired_order_ids');
        $error ? $additionalInformation->willThrowException($error) : $additionalInformation->willReturn($acquiredOrderIds);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(self::ORDER_ID);
        $order->method('getIncrementId')->willReturn(self::INCREMENT_ID);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getGrandTotal')->willReturn(25.5);
        $order->method('getOrderCurrencyCode')->willReturn('GBP');
        $order->method('getPayment')->willReturn($payment);

        $this->givenOrderList([self::ORDER_ID => $order]);
    }

    private function givenTransactions(array $transactionsByOrderId): void
    {
        $this->transactionClient->method('list')->willReturnCallback(
            fn (array $query) => $this->listResponse($transactionsByOrderId[$query[Transaction::ORDER_ID]] ?? [])
        );
    }

    private function givenFreshOrder(string $state, bool $hasInvoices): void
    {
        $this->freshOrder->method('getState')->willReturn($state);
        $this->freshOrder->method('hasInvoices')->willReturn($hasInvoices);
    }

    private function expectNoCancellation(): void
    {
        $this->freshOrder->expects($this->never())->method('registerCancellation');
        $this->orderRepository->expects($this->never())->method('save');
    }

    private function listResponse(array $transactions): array
    {
        return ['meta' => ['count' => count($transactions), 'total' => count($transactions)], 'data' => $transactions];
    }

    private function transaction(string $orderId, string $status, string $transactionId, float $amount = 25.50, string $type = 'sale'): array
    {
        return [
            'transaction_id' => $transactionId,
            'status' => $status,
            'transaction_type' => $type,
            'transaction' => ['order_id' => $orderId, 'amount' => $amount, 'currency' => 'GBP']
        ];
    }
}
