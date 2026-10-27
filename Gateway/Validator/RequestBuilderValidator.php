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

namespace Acquired\Payments\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Acquired\Payments\Exception\Command\BuilderException;
use Acquired\Payments\Api\Data\TransactionResponseInterface;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Gateway\Config\Card\Config;
use Psr\Log\LoggerInterface;

/**
 * @class RequestBuilderValidator
 *
 * Validates the data originated from frontend for purposes of authorization or capture of a transaction
 */
class RequestBuilderValidator extends AbstractValidator
{

    public function __construct(
        private readonly Gateway $gateway,
        private readonly TransactionDataIntegrityValidator $transactionDataIntegrityValidator,
        private readonly Config $cardConfig,
        private readonly LoggerInterface $logger
    ) {}

    public function validate(array $paymentAdditionalData)
    {
        if (empty($paymentAdditionalData['transaction_id'])) {
            throw new BuilderException(__('Transaction ID is required.'));
        }

        if (empty($paymentAdditionalData['order_id'])) {
            throw new BuilderException(__('Order ID is required.'));
        }

        if (empty($paymentAdditionalData['timestamp'])) {
            throw new BuilderException(__('Timestamp is required.'));
        }

        // fetch transaction from gateway and check 3ds secure status
        $transaction = $this->gateway->getTransaction()->get($paymentAdditionalData['transaction_id']);

        if (!isset($transaction[TransactionResponseInterface::STATUS]) || $transaction[TransactionResponseInterface::STATUS] !== 'success') {
            throw new BuilderException(__('Transaction is not successful.'));
        }

        $transactionId = $transaction[TransactionResponseInterface::TRANSACTION_ID] ?? null;
        $orderId = $transaction[TransactionResponseInterface::TRANSACTION]['order_id'] ?? null;
        $tds = $transaction[TransactionResponseInterface::TDS] ?? null;

        if ($transactionId !== $paymentAdditionalData['transaction_id']) {
            throw new BuilderException(__('Transaction ID does not match.'));
        }
        if ($orderId !== $paymentAdditionalData['order_id']) {
            throw new BuilderException(__('Order ID does not match.'));
        }
        
        $method = strtolower(trim($transaction[TransactionResponseInterface::PAYMENT_METHOD] ?? ''));
        
        $isApplePay = is_array($method)
            ? in_array('apple_pay', $method, true)
            : ($method === 'apple_pay');
        
        $isMoto = isset($transaction['transaction']['moto']) && (int)$transaction['transaction']['moto'] === 1;
        
        $shouldValidateHash = $this->cardConfig->isTdsActive()
            && empty($paymentAdditionalData['multishipping'])
            && !$isApplePay
            && !$isMoto;

        if($shouldValidateHash) {
            if(empty($paymentAdditionalData['hash'])) {
                throw new BuilderException(__('Hash is required for 3DS secure transactions.'));
            }

            $this->transactionDataIntegrityValidator->validateIntegrity([
                TransactionDataIntegrityValidator::STATUS_KEY => $transaction[TransactionResponseInterface::STATUS],
                TransactionDataIntegrityValidator::TRANSACTION_ID_KEY => $paymentAdditionalData['transaction_id'],
                TransactionDataIntegrityValidator::ORDER_ID_KEY => $paymentAdditionalData['order_id'],
                TransactionDataIntegrityValidator::TIMESTAMP_KEY => $paymentAdditionalData['timestamp'],
                TransactionDataIntegrityValidator::HASH_KEY => $paymentAdditionalData['hash']
            ]);
        }
    }

}