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

namespace Acquired\Payments\Test\Unit\Setup\Patch\Data;

use Acquired\Payments\Setup\Patch\Data\FixPayByBankRedirectUrl;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class FixPayByBankRedirectUrlTest extends TestCase
{
    public function testOnlyRewritesTheBrokenRedirectUrl()
    {
        $connection = $this->createMock(AdapterInterface::class);
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->with('core_config_data')->willReturn('core_config_data');

        $connection->expects($this->once())
            ->method('update')
            ->with(
                'core_config_data',
                ['value' => 'acquired/hosted/response'],
                [
                    'path = ?' => 'payment/acquired_pay_by_bank/redirect_url',
                    'value IN (?)' => ['acquired/hosted/redirect', 'acquired/hosted/redirect/', '/acquired/hosted/redirect']
                ]
            );

        (new FixPayByBankRedirectUrl($setup))->apply();
    }
}
