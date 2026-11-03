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

namespace Acquired\Payments\Gateway\Response\PayByBank;

use Exception;
use Acquired\Payments\Controller\Hosted\Context as HostedContext;
use Acquired\Payments\Exception\Command\HandlerException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Psr\Log\LoggerInterface;

class PaymentDetailsHandler implements HandlerInterface
{

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array $handlingSubject
     * @param array $response
     * @return void
     * @throws HandlerException
     */
    public function handle(array $handlingSubject, array $response): void
    {
        try {
            /** @var OrderPaymentInterface $payment */
            $payment = SubjectReader::readPayment($handlingSubject)->getPayment();

            $this->setTransactionDataToPayment($payment, $response);
            $this->setAdditionalTransactionData($payment, $response);

            $payment->getOrder()->setCanSendNewEmailFlag(false);

            $payment->setIsTransactionClosed(false);
            $payment->setIsTransactionPending(true);
            $payment->setShouldCloseParentTransaction(false);
        } catch (Exception $e) {
            $message = __('Payment Details Handler failed: %1', $e->getMessage());
            $this->logger->critical($message, ['exception' => $e]);

            throw new HandlerException($message);
        }
    }

    /**
     * Set Transaction data to payment
     *
     * @param OrderPaymentInterface $payment
     * @param array $transaction
     */
    private function setTransactionDataToPayment(OrderPaymentInterface $payment, array $transaction): void
    {
        if (!empty($transaction['link_id'])) {
            $payment->setTransactionId($transaction['link_id']);
        }
    }

    /**
     * Set additional transaction data to payment additional information
     *
     * @param OrderPaymentInterface $payment
     * @param array $transaction
     * @return void
     */
    private function setAdditionalTransactionData(OrderPaymentInterface $payment, array $transaction): void
    {
        if (!empty($transaction['link_id'])) {
            $payment->setAdditionalInformation('link_id', $transaction['link_id']);
        }

        $incrementId = $payment->getOrder()->getIncrementId();
        if ($incrementId) {
            $payment->setAdditionalInformation(HostedContext::ACQUIRED_ORDER_IDS, [$incrementId]);
        }
    }
}
