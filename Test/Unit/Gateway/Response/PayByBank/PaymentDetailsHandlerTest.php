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

namespace Acquired\Payments\Test\Unit\Gateway\Response\PayByBank;

use Acquired\Payments\Gateway\Response\PayByBank\PaymentDetailsHandler;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PaymentDetailsHandlerTest extends TestCase
{
    public function testRecordsOrderIdSentToAcquired()
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000007');

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);

        $recorded = [];
        $payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) use (&$recorded, $payment) {
            $recorded[$key] = $value;
            return $payment;
        });

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        (new PaymentDetailsHandler($this->createMock(LoggerInterface::class)))
            ->handle(['payment' => $paymentDataObject], ['link_id' => 'link-1']);

        $this->assertSame('link-1', $recorded['link_id']);
        $this->assertSame(['000000007'], $recorded['acquired_order_ids']);
    }
}
