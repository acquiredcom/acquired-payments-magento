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

namespace Acquired\Payments\Service;

use Acquired\Payments\Client\Gateway;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * @class CreatePaymentLink
 *
 * Creates a Hosted Checkout payment link, retrying once without the customer when Acquired rejects it.
 */
class CreatePaymentLink
{
    /**
     * @param Gateway $gateway
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * A stored Acquired customer ID can be invalid for the current credentials, e.g. after a change of Acquired account.
     *
     * @param array $payload
     * @return array|null
     * @throws Exception
     */
    public function execute(array $payload): ?array
    {
        try {
            return $this->gateway->getPaymentLinks()->generateLinkId($payload);
        } catch (Exception $e) {
            if (empty($payload['customer']['customer_id'])) {
                throw $e;
            }

            $this->logger->warning(
                __('Payment link for order %1 failed with the stored Acquired customer, retrying without it.', $payload['transaction']['order_id'] ?? '')
            );
            unset($payload['customer']['customer_id']);

            return $this->gateway->getPaymentLinks()->generateLinkId($payload);
        }
    }
}
