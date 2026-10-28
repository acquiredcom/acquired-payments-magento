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

namespace Acquired\Payments\Gateway\Config\Card;

use Acquired\Payments\Observer\AcquiredCardDataAssignObserver;
use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Magento\Payment\Model\MethodInterface;

/**
 * Order-first card orders are always placed with authorize; whether the card is captured is decided by the
 * Acquired session and applied when the payment is completed
 */
class PaymentActionValueHandler implements ValueHandlerInterface
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @param array $subject
     * @param int|null $storeId
     * @return mixed
     */
    public function handle(array $subject, $storeId = null)
    {
        $payment = isset($subject['payment']) ? $subject['payment']->getPayment() : null;

        if ($payment && $payment->getAdditionalInformation(AcquiredCardDataAssignObserver::ORDER_FIRST)) {
            return MethodInterface::ACTION_AUTHORIZE;
        }

        return $this->config->getValue('payment_action', $storeId);
    }
}
