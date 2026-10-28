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

namespace Acquired\Payments\Test\Unit\Service\OrderPayment;

use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Client\Transaction as TransactionClient;
use Acquired\Payments\Service\OrderPayment\Cancel;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CancelTest extends TestCase
{
    private $transactionClient;
    private $orderRepository;
    private $checkoutSession;
    private $order;
    private $cancel;

    protected function setUp(): void
    {
        $this->transactionClient = $this->createMock(TransactionClient::class);
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('getTransaction')->willReturn($this->transactionClient);

        $this->order = $this->createMock(Order::class);
        $this->order->method('getEntityId')->willReturn(7);
        $this->order->method('getIncrementId')->willReturn('000000400');
        $this->order->method('getPayment')->willReturn($this->createMock(Payment::class));

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->with(7)->willReturn($this->order);

        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['setLastRealOrderId'])
            ->onlyMethods(['restoreQuote'])
            ->getMock();

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        $this->cancel = new Cancel(
            $gateway,
            $this->orderRepository,
            $this->checkoutSession,
            $this->createMock(ManagerInterface::class),
            $lockManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testOrderInPaymentReviewIsCancelledAndCartRestored()
    {
        $this->order->method('getState')->willReturnOnConsecutiveCalls(
            Order::STATE_PAYMENT_REVIEW,
            Order::STATE_CANCELED
        );

        $this->order->expects($this->once())->method('registerCancellation')->with('Declined');
        $this->orderRepository->expects($this->once())->method('save')->with($this->order);
        $this->transactionClient->expects($this->never())->method('void');
        $this->checkoutSession->expects($this->once())->method('restoreQuote');

        $this->assertTrue($this->cancel->execute($this->order, 'Declined', true));
    }

    public function testTakenPaymentIsVoided()
    {
        $this->order->method('getState')->willReturnOnConsecutiveCalls(
            Order::STATE_PAYMENT_REVIEW,
            Order::STATE_CANCELED
        );

        $this->transactionClient->expects($this->once())->method('void')->with('txn-1')
            ->willReturn(['status' => 'success']);

        $this->cancel->execute($this->order, 'Amount mismatch', false, 'txn-1');
    }

    public function testPaidOrderIsNotCancelled()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);

        $this->order->expects($this->never())->method('registerCancellation');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->assertFalse($this->cancel->execute($this->order, 'Declined', true));
    }
}
