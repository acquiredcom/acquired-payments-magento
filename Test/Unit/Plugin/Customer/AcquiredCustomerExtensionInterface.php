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

namespace Acquired\Payments\Test\Unit\Plugin\Customer;

use Magento\Customer\Api\Data\CustomerExtensionInterface;

/**
 * Declares the acquired_customer_id extension attribute methods, which the unit test environment
 * does not generate on CustomerExtensionInterface.
 */
interface AcquiredCustomerExtensionInterface extends CustomerExtensionInterface
{
    /**
     * @return string|null
     */
    public function getAcquiredCustomerId();

    /**
     * @param string $acquiredCustomerId
     * @return $this
     */
    public function setAcquiredCustomerId($acquiredCustomerId);
}
