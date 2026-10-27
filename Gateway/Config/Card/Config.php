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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Acquired\Payments\Model\StoreConfigResolver;
use Magento\Payment\Model\MethodInterface;

/**
 * @class CardConfig
 *
 * Handles Card Method configuration settings for the Acquired Limited Payment module.
 */
class Config extends GatewayConfig
{
    private const KEY_ACTIVE = 'active';
    private const KEY_TITLE = 'title';
    private const KEY_PAYMENT_ACTION = 'payment_action';
    private const KEY_CREATE_CARD = 'create_card';
    private const KEY_TDS_ACTIVE = 'tds_active';
    private const KEY_TDS_CHALLENGE_PREFERENCE = 'tds_challenge_preference';
    private const KEY_TDS_CONTACT_URL = 'tds_contact_url';
    private const KEY_STYLE = 'style';
    private const KEY_TDS_WINDOW_SIZE = 'tds_window_size';
    private const KEY_GOOGLE_PAY_ENABLED = 'google_pay_enabled';
    private const KEY_APPLE_PAY_ENABLED = 'apple_pay_enabled';

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
     * Get the configured payment action.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getPaymentAction(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_PAYMENT_ACTION, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Check if saving the credit card on ACQUIRED.com customer account is enabled.
     *
     * @param int|null $storeId
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function isCreateCardEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_CREATE_CARD, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Check if 3-D Secure is enabled.
     *
     * @param int|null $storeId
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function isTdsActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_TDS_ACTIVE, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get the 3-D Secure challenge preference.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getTdsChallengePreference(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_TDS_CHALLENGE_PREFERENCE, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get the 3-D Secure contact URL.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getTdsContactUrl(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_TDS_CONTACT_URL, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get custom card component style in JSON format.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getStyle(?int $storeId = null): ?string
    {
        return $this->getValue(self::KEY_STYLE, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Get 3ds window size
     *
     * @param int|null $storeId
     * @return string
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getTdsWindowSize(?int $storeId = null): ?string
    {
        $size = $this->getValue(self::KEY_TDS_WINDOW_SIZE, $storeId ?? $this->storeConfigResolver->getStoreId());

        return $size ?: '750';
    }

    /**
     * Check if Google Pay is enabled.
     *
     * @param int|null $storeId
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function isGooglePayEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_GOOGLE_PAY_ENABLED, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Check if Apple Pay is enabled.
     *
     * @param int|null $storeId
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function isApplePayEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_APPLE_PAY_ENABLED, $storeId ?? $this->storeConfigResolver->getStoreId());
    }

    /**
     * Determines whether the capture action is enabled based on the configured payment action.
     *
     * @param int|null $storeId Optional store ID for which to get the configuration.
     * @return bool True if capture action is enabled (AUTHORIZE_CAPTURE), otherwise false (AUTHORIZE only).
     * @throws LocalizedException If an unsupported payment action is configured.
     */
    public function getCaptureAction(?int $storeId = null): bool
    {
        $paymentAction = $this->getPaymentAction($storeId);
        if ($paymentAction === MethodInterface::ACTION_AUTHORIZE) {
            return false;
        } elseif ($paymentAction === MethodInterface::ACTION_AUTHORIZE_CAPTURE) {
            return true;
        } else {
            throw new LocalizedException(__('Unsupported payment action'));
        }
    }
}
