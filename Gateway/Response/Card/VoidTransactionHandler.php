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

namespace Acquired\Payments\Gateway\Response\Card;

use Exception;
use Acquired\Payments\Exception\Command\HandlerException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Psr\Log\LoggerInterface;

class VoidTransactionHandler implements HandlerInterface
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
            $payment->setLastTransId($response['transaction_id']);
            $payment->setAdditionalInformation('transaction_id', $response['transaction_id']);
        } catch (Exception $e) {
            $message = __('Void Transaction Handler failed: %1', $e->getMessage());
            $this->logger->critical($message, ['exception' => $e]);

            throw new HandlerException($message);
        }
    }
}
