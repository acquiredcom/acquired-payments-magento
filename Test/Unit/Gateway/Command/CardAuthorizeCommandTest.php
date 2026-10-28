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

namespace Acquired\Payments\Test\Unit\Gateway\Command;

use Acquired\Payments\Gateway\Command\CardAuthorizeCommand;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

class CardAuthorizeCommandTest extends TestCase
{
    private $orderFirstCommand;
    private $paidCommand;
    private $command;

    protected function setUp(): void
    {
        $this->orderFirstCommand = $this->createMock(CommandInterface::class);
        $this->paidCommand = $this->createMock(CommandInterface::class);
        $this->command = new CardAuthorizeCommand($this->orderFirstCommand, $this->paidCommand);
    }

    public function testOrderFirstPaymentUsesPendingCommand()
    {
        $subject = $this->getSubject('1');

        $this->orderFirstCommand->expects($this->once())->method('execute')->with($subject);
        $this->paidCommand->expects($this->never())->method('execute');

        $this->command->execute($subject);
    }

    public function testPaidPaymentUsesExistingCommand()
    {
        $subject = $this->getSubject(null);

        $this->orderFirstCommand->expects($this->never())->method('execute');
        $this->paidCommand->expects($this->once())->method('execute')->with($subject);

        $this->command->execute($subject);
    }

    private function getSubject(?string $orderFirst): array
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->with('order_first')->willReturn($orderFirst);

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return ['payment' => $paymentDataObject];
    }
}
