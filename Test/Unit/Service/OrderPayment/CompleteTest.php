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
use Acquired\Payments\Exception\Payment\PaymentVerificationException;
use Acquired\Payments\Gateway\Config\Card\Config as CardConfig;
use Acquired\Payments\Gateway\Response\Card\TransactionDataMapper;
use Acquired\Payments\Service\OrderPayment\Complete;
use Magento\Framework\DB\Transaction as DbTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CompleteTest extends TestCase
{
    private $transactionClient;
    private $orderRepository;
    private $dbTransaction;
    private $order;
    private $payment;
    private $complete;

    protected function setUp(): void
    {
        $this->transactionClient = $this->createMock(TransactionClient::class);
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('getTransaction')->willReturn($this->transactionClient);

        $this->payment = $this->createMock(Payment::class);
        $this->order = $this->createMock(Order::class);
        $this->order->method('getEntityId')->willReturn(7);
        $this->order->method('getIncrementId')->willReturn('000000400');
        $this->order->method('getGrandTotal')->willReturn(55.0);
        $this->order->method('getBaseGrandTotal')->willReturn(55.0);
        $this->order->method('getOrderCurrencyCode')->willReturn('GBP');
        $this->order->method('getPayment')->willReturn($this->payment);

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->with(7)->willReturn($this->order);

        $this->dbTransaction = $this->createMock(DbTransaction::class);
        $this->dbTransaction->method('addObject')->willReturnSelf();
        $transactionFactory = $this->createMock(TransactionFactory::class);
        $transactionFactory->method('create')->willReturn($this->dbTransaction);

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        $cardConfig = $this->createMock(CardConfig::class);
        $cardConfig->method('getCaptureAction')->willReturn(false);

        $this->complete = new Complete(
            $gateway,
            $this->orderRepository,
            $this->createMock(TransactionDataMapper::class),
            $transactionFactory,
            $this->createMock(OrderSender::class),
            $this->createMock(InvoiceSender::class),
            $lockManager,
            $cardConfig,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testAuthorisedPaymentIsRegisteredAsAuthorisation()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->transactionClient->method('get')->willReturn($this->getTransaction(['transaction_type' => 'auth_only']));

        $this->payment->expects($this->once())->method('registerAuthorizationNotification')->with(55.0);
        $this->payment->expects($this->never())->method('registerCaptureNotification');
        $this->dbTransaction->expects($this->once())->method('save');

        $this->complete->execute($this->order, 'txn-1');
    }

    public function testCapturedPaymentIsRegisteredAsCapture()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->transactionClient->method('get')->willReturn($this->getTransaction(['transaction_type' => 'auth_capture']));

        $this->payment->expects($this->once())->method('registerCaptureNotification')->with(55.0, true);
        $this->payment->expects($this->never())->method('registerAuthorizationNotification');

        $this->complete->execute($this->order, 'txn-1');
    }

    public function testOrderAlreadyCompletedIsLeftAlone()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);

        $this->transactionClient->expects($this->never())->method('get');
        $this->dbTransaction->expects($this->never())->method('save');

        $this->complete->execute($this->order, 'txn-1');
    }

    public function testDeclinedPaymentIsRejectedWithoutVoid()
    {
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->transactionClient->method('get')->willReturn($this->getTransaction(['status' => 'declined']));

        try {
            $this->complete->execute($this->order, 'txn-1');
            $this->fail('Expected PaymentVerificationException');
        } catch (PaymentVerificationException $e) {
            $this->assertFalse($e->isPaymentTaken());
        }
    }

    /**
     * @dataProvider mismatchProvider
     */
    public function testMismatchedPaymentIsRejectedForVoid(array $override)
    {
        $this->order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $this->transactionClient->method('get')->willReturn($this->getTransaction($override));
        $this->dbTransaction->expects($this->never())->method('save');

        try {
            $this->complete->execute($this->order, 'txn-1');
            $this->fail('Expected PaymentVerificationException');
        } catch (PaymentVerificationException $e) {
            $this->assertTrue($e->isPaymentTaken());
        }
    }

    public static function mismatchProvider(): array
    {
        return [
            'amount' => [['transaction' => ['order_id' => '000000400', 'amount' => 60, 'currency' => 'gbp']]],
            'order' => [['transaction' => ['order_id' => '000000399', 'amount' => 55, 'currency' => 'gbp']]],
            'currency' => [['transaction' => ['order_id' => '000000400', 'amount' => 55, 'currency' => 'eur']]],
        ];
    }

    private function getTransaction(array $override): array
    {
        return array_merge([
            'transaction_id' => 'txn-1',
            'status' => 'success',
            'transaction_type' => 'auth_only',
            'transaction' => ['order_id' => '000000400', 'amount' => 55, 'currency' => 'gbp'],
        ], $override);
    }
}
