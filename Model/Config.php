<?php

/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */

namespace Acquired\Payments\Model;

use Magento\Framework\App\ProductMetadataInterface;

class Config
{
    public static $version = "1.1.2";

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata
    ) {
    }

    public function getClientVersion()
    {
        return 'M: ' . $this->productMetadata->getEdition() . 'A:' . self::$version;
    }
}