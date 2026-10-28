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

namespace Acquired\Payments\Exception\Payment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * @class PaymentVerificationException
 *
 * Acquired returned the transaction, but it is not a successful payment for this order.
 */
class PaymentVerificationException extends LocalizedException
{
    private bool $paymentTaken = false;

    /**
     * The transaction succeeded but does not match the order, so the money must be released
     *
     * @param Phrase $phrase
     * @return self
     */
    public static function paymentTaken(Phrase $phrase): self
    {
        $exception = new self($phrase);
        $exception->paymentTaken = true;

        return $exception;
    }

    /**
     * @return bool
     */
    public function isPaymentTaken(): bool
    {
        return $this->paymentTaken;
    }
}
