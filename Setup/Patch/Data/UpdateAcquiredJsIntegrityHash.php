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
use Acquired\Payments\Block\AcquiredJs;

class UpdateAcquiredJsIntegrityHash implements DataPatchInterface
{
    private const SDK_V1_1_HASH = 'sha384-uEWrid7+SjZbfDvQmobDdKO/2ofqACn5KXS9DKANcM0UfHIq+6X1ItKH+dBqcsaV';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * Remove saved copies of the acquired.js v1.1 hash so the v1.2 default from config.xml applies
     *
     * @return $this
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            [
                'path = ?' => AcquiredJs::XML_CONFIG_PATH_JS_INTEGRITY_HASH,
                'value = ?' => self::SDK_V1_1_HASH
            ]
        );

        return $this;
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }
}
