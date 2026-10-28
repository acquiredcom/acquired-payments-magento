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

namespace Acquired\Payments\Gateway\Response\OrderFirst;

use Acquired\Payments\Observer\AcquiredCardDataAssignObserver;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * Leaves a newly placed order in payment review until Acquired confirms the payment
 */
class PendingPaymentHandler implements HandlerInterface
{
    /**
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response): void
    {
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($handlingSubject)->getPayment();

        $payment->setAdditionalInformation(AcquiredCardDataAssignObserver::ORDER_FIRST, true);
        $payment->getOrder()->setCanSendNewEmailFlag(false);

        $payment->setIsTransactionClosed(false);
        $payment->setIsTransactionPending(true);
        $payment->setShouldCloseParentTransaction(false);
    }
}
