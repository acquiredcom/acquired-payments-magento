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

namespace Acquired\Payments\Gateway\Config\Hosted;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Acquired\Payments\Model\StoreConfigResolver;

/**
 * @class HostedConfig
 *
 * Handles Hosted Method configuration settings for the Acquired Limited Payment module.
 */
class Config extends GatewayConfig
{
    private const KEY_ACTIVE = 'active';
    private const KEY_BANK_ONLY = 'bank_only';
    private const KEY_TITLE = 'title';
    private const KEY_REDIRECT_URL = 'redirect_url';
    private const KEY_WEBHOOK_URL = 'webhook_url';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreConfigResolver $storeConfigResolver
     * @param string|null $methodCode
     * @param string $pathPattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly StoreConfigResolver $storeConfigResolver,
        ?string $methodCode = null,
        string $pathPattern = parent::DEFAULT_PATH_PATTERN,
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    /**
     * Check is payment method active.
     *
     * @param int|null $storeId
     * @return bool true if the payment method is enabled, otherwise false.
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Check if only bank payments should be processed?
     *
     * @param int|null $storeId
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function isBankOnly(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_BANK_ONLY, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get the method title as it will appear for customers in checkout.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getTitle(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_TITLE, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get the redirect URL for the hosted payment page.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getRedirectUrl(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_REDIRECT_URL, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get the webhook URL for the hosted payment page.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getWebhookUrl(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_WEBHOOK_URL, $storeId ?? $this->storeConfigResolver->getStoreId());
    }
}
