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

use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Copies an Acquired card transaction onto the Magento order payment
 */
class TransactionDataMapper
{
    /**
     * @param OrderPaymentInterface $payment
     * @param array $transaction
     * @return void
     */
    public function map(OrderPaymentInterface $payment, array $transaction): void
    {
        $this->setTransactionDataToPayment($payment, $transaction);
        $this->setAdditionalTransactionData($payment, $transaction);
    }

    /**
     * Set Transaction data to payment
     *
     * @param OrderPaymentInterface $payment
     * @param array $transaction
     */
    private function setTransactionDataToPayment(OrderPaymentInterface $payment, array $transaction): void
    {
        $payment->setLastTransId($transaction['transaction_id']);
        $payment->setTransactionId($transaction['transaction_id']);
        if (!empty($transaction['card'])) {
            $payment->setCcType($transaction['card']['scheme'] ?? null);
            $payment->setCcLast4($transaction['card']['number'] ?? null);
            $payment->setCcExpMonth($transaction['card']['expiry_month'] ?? null);
            $payment->setCcExpYear($transaction['card']['expiry_year'] ?? null);
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
        $payment->setAdditionalInformation('payment_method', $transaction['payment_method'] ?? null);
        $payment->setAdditionalInformation('mid', $transaction['mid'] ?? null);
        $payment->setAdditionalInformation('transaction_id', $transaction['transaction_id']);
        $payment->setAdditionalInformation('authorization_code', $transaction['authorization_code'] ?? null);

        if (!empty($transaction['card'])) {
            $payment->setAdditionalInformation('cc_type', $transaction['card']['scheme'] ?? null);
            $payment->setAdditionalInformation('holder_name', $transaction['card']['holder_name'] ?? null);
            $payment->setAdditionalInformation('cc_last4', $transaction['card']['number'] ?? null);
            $payment->setAdditionalInformation(
                'cc_exp',
                ($transaction['card']['expiry_month'] ?? null) . '/' . ($transaction['card']['expiry_year'] ?? null)
            );
        }

        if (!empty($transaction['check'])) {
            $payment->setAdditionalInformation('avs_line1', $transaction['check']['avs_line1'] ?? null);
            $payment->setAdditionalInformation('avs_postcode', $transaction['check']['avs_postcode'] ?? null);
            $payment->setAdditionalInformation('cvv', $transaction['check']['cvv'] ?? null);
        }
    }
}
