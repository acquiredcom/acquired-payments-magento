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

namespace Acquired\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * @class FixPayByBankRedirectUrl
 *
 * Replaces the saved Pay By Bank redirect URL when it still points at the non-existent acquired/hosted/redirect route.
 */
class FixPayByBankRedirectUrl implements DataPatchInterface
{
    private const CONFIG_PATH = 'payment/acquired_pay_by_bank/redirect_url';
    private const BROKEN_VALUES = ['acquired/hosted/redirect', 'acquired/hosted/redirect/', '/acquired/hosted/redirect', '/acquired/hosted/redirect/'];
    private const FIXED_VALUE = 'acquired/hosted/response';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->update(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['value' => self::FIXED_VALUE],
            ['path = ?' => self::CONFIG_PATH, 'value IN (?)' => self::BROKEN_VALUES]
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
