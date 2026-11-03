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

namespace Acquired\Payments\Test\Unit\Service;

use Acquired\Payments\Service\InvoicePaidOrder;
use Acquired\Payments\Ui\Method\CardProvider;
use Acquired\Payments\Ui\Method\PayByBankProvider;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;

class InvoicePaidOrderTest extends TestCase
{
    private const ORDER_ID = 42;
    private const TRANSACTION_ID = 'a7e3fde5-5b83-44f4-9915-782bc7121717';

    private $lockManager;
    private $orderFactory;
    private $invoiceService;
    private $transactionFactory;
    private $orderSender;
    private $invoiceSender;
    private $order;
    private $payment;
    private $paymentMethod = PayByBankProvider::CODE;
    private $invoicePaidOrder;

    protected function setUp(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->orderFactory = $this->createMock(OrderFactory::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->transactionFactory = $this->createMock(TransactionFactory::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->invoiceSender = $this->createMock(InvoiceSender::class);

        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturnCallback(fn () => $this->paymentMethod);

        $this->order = $this->createMock(Order::class);
        $this->order->method('load')->willReturnSelf();
        $this->order->method('getId')->willReturn(self::ORDER_ID);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $this->order->method('getPayment')->willReturn($this->payment);
        $this->orderFactory->method('create')->willReturn($this->order);

        $this->invoicePaidOrder = new InvoicePaidOrder(
            $this->lockManager,
            $this->orderFactory,
            $this->invoiceService,
            $this->transactionFactory,
            $this->orderSender,
            $this->invoiceSender
        );
    }

    public function testInvoicesOrderAwaitingPayment()
    {
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->order->method('hasInvoices')->willReturn(false);
        $this->order->method('canInvoice')->willReturn(true);
        $this->order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING);

        $this->expectInvoiceSaved();

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function testInvoicesAuthorisedCardMultishippingOrder()
    {
        $this->paymentMethod = CardProvider::CODE;
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->order->method('hasInvoices')->willReturn(false);
        $this->order->method('canInvoice')->willReturn(true);
        $this->order->expects($this->never())->method('setState');

        $this->expectInvoiceSaved();

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID, true);

        $this->assertTrue($result['success']);
    }

    public function testDoesNotInvoiceCardOrderOutsideMultishipping()
    {
        $this->paymentMethod = CardProvider::CODE;
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->order->method('hasInvoices')->willReturn(false);

        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function testCancelledCardMultishippingOrderIsNotRetried()
    {
        $this->paymentMethod = CardProvider::CODE;
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_CANCELED);
        $this->order->method('hasInvoices')->willReturn(false);

        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID, true);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function testOrderThatCannotBeInvoicedIsRetryable()
    {
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->order->method('hasInvoices')->willReturn(false);
        $this->order->method('canInvoice')->willReturn(false);

        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    private function expectInvoiceSaved(): void
    {
        $history = $this->createMock(History::class);
        $history->method('setIsCustomerNotified')->willReturnSelf();
        $this->order->method('addCommentToStatusHistory')->willReturn($history);

        $this->payment->expects($this->once())->method('setLastTransId')->with(self::TRANSACTION_ID);
        $this->payment->expects($this->once())->method('setTransactionId')->with(self::TRANSACTION_ID);

        $this->payment->expects($this->never())->method('save');

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrder')->willReturn($this->order);
        $invoice->expects($this->once())->method('register');
        $invoice->expects($this->never())->method('save');
        $this->invoiceService->expects($this->once())->method('prepareInvoice')->with($this->order)->willReturn($invoice);

        $transaction = $this->createMock(Transaction::class);
        $transaction->method('addObject')->willReturnSelf();
        $transaction->expects($this->once())->method('save');
        $this->transactionFactory->method('create')->willReturn($transaction);

        $this->orderSender->expects($this->once())->method('send')->with($this->order);
        $this->invoiceSender->expects($this->once())->method('send')->with($invoice);
    }

    public function testInvoicingErrorIsRetryable()
    {
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->order->method('hasInvoices')->willReturn(false);
        $this->order->method('canInvoice')->willReturn(true);
        $this->invoiceService->method('prepareInvoice')
            ->willThrowException(new LocalizedException(__('Could not prepare invoice')));

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    public function testDoesNotInvoiceAlreadyInvoicedOrder()
    {
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->order->method('hasInvoices')->willReturn(true);

        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
    }

    public function testDoesNotInvoiceOrderNoLongerInPaymentReview()
    {
        $this->givenLockAcquired();
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->order->method('hasInvoices')->willReturn(false);

        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
    }

    public function testDoesNotInvoiceWhenLockIsHeld()
    {
        $this->lockManager->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->orderFactory->expects($this->never())->method('create');
        $this->invoiceService->expects($this->never())->method('prepareInvoice');

        $result = $this->invoicePaidOrder->execute(self::ORDER_ID, self::TRANSACTION_ID);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
    }

    private function givenLockAcquired(): void
    {
        $this->lockManager->expects($this->once())
            ->method('lock')
            ->with('acquired_invoice_' . self::ORDER_ID)
            ->willReturn(true);
        $this->lockManager->expects($this->once())
            ->method('unlock')
            ->with('acquired_invoice_' . self::ORDER_ID);
    }
}
