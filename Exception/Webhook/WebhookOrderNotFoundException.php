<?php

/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */

namespace Acquired\Payments\Exception\Webhook;

use Magento\Framework\Exception\LocalizedException;

/**
 * @class WebhookOrderNotFoundException
 *
 * Represents an exception for webhook data that references an unknown order.
 */
class WebhookOrderNotFoundException extends LocalizedException
{
}
