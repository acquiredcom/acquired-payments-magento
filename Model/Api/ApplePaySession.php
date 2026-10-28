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

namespace Acquired\Payments\Model\Api;

use Acquired\Payments\Api\Data\ApplePaySessionDataInterface;

class ApplePaySession implements ApplePaySessionDataInterface
{

    /**
     * @var string|null
     */
    private ?string $displayName = null;

    /**
     * @var string|null
     */
    private ?string $domainName = null;

    /**
     * @var int|null
     */
    private ?int $epochTimestamp = null;

    /**
     * @var int|null
     */
    private ?int $expiresAt = null;

    /**
     * @var string|null
     */
    private ?string $merchantIdentifier = null;

    /**
     * @var string|null
     */
    private ?string $merchantSessionIdentifier = null;

    /**
     * @var string|null
     */
    private ?string $nonce = null;

    /**
     * @var string|null
     */
    private ?string $operationalAnalyticsIdentifier = null;

    /**
     * @var string|null
     */
    private ?string $pspId = null;

    /**
     * @var int|null
     */
    private ?int $retries = null;

    /**
     * @var string|null
     */
    private ?string $signature = null;

    public function __construct(array $data) {
        $this->displayName = $data['displayName'] ?? null;
        $this->domainName = $data['domainName'] ?? null;
        $this->epochTimestamp = $data['epochTimestamp'] ?? null;
        $this->expiresAt = $data['expiresAt'] ?? null;
        $this->merchantIdentifier = $data['merchantIdentifier'] ?? null;
        $this->merchantSessionIdentifier = $data['merchantSessionIdentifier'] ?? null;
        $this->nonce = $data['nonce'] ?? null;
        $this->operationalAnalyticsIdentifier = $data['operationalAnalyticsIdentifier'] ?? null;
        $this->pspId = $data['pspId'] ?? null;
        $this->retries = $data['retries'] ?? null;
        $this->signature = $data['signature'] ?? null;
    }

    /**
     * Set Display Name
     *
     * @param string $displayName
     * @return self
     */
    public function setDisplayName(string $displayName): self {
        $this->displayName = $displayName;
        return $this;
    }

    /**
     * Get Session Id
     *
     * @return string|null
     */
    public function getDisplayName(): ?string {
        return $this->displayName;
    }

    /**
     * Set Domain Name
     *
     * @param string $domainName
     * @return self
     */
    public function setDomainName(string $domainName): self {
        $this->domainName = $domainName;
        return $this;
    }

    /**
     * Get Domain Name
     *
     * @return string|null
     */
    public function getDomainName(): ?string {
        return $this->domainName;
    }

    /**
     * Set Epoch Timestamp
     *
     * @param int $epochTimestamp
     * @return self
     */
    public function setEpochTimestamp(int $epochTimestamp): self {
        $this->epochTimestamp = $epochTimestamp;
        return $this;
    }

    /**
     * Get Epoch Timestamp
     *
     * @return int|null
     */
    public function getEpochTimestamp(): ?int {
        return $this->epochTimestamp;
    }

    /**
     * Set Expires At
     *
     * @param int $expiresAt
     * @return self
     */
    public function setExpiresAt(int $expiresAt): self {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    /**
     * Get Expires At
     *
     * @return int|null
     */
    public function getExpiresAt(): ?int {
        return $this->expiresAt;
    }

    /**
     * Set Merchant Identifier
     *
     * @param string $merchantIdentifier
     * @return self
     */
    public function setMerchantIdentifier(string $merchantIdentifier): self {
        $this->merchantIdentifier = $merchantIdentifier;
        return $this;
    }

    /**
     * Get Merchant Identifier
     *
     * @return string|null
     */
    public function getMerchantIdentifier(): ?string {
        return $this->merchantIdentifier;
    }

    /**
     * Set Merchant Session Identifier
     *
     * @param string $merchantSessionIdentifier
     * @return self
     */
    public function setMerchantSessionIdentifier(string $merchantSessionIdentifier): self {
        $this->merchantSessionIdentifier = $merchantSessionIdentifier;
        return $this;
    }

    /**
     * Get Merchant Session Identifier
     *
     * @return string|null
     */
    public function getMerchantSessionIdentifier(): ?string {
        return $this->merchantSessionIdentifier;
    }

    /**
     * Set Nonce
     *
     * @param string $nonce
     * @return self
     */
    public function setNonce(string $nonce): self {
        $this->nonce = $nonce;
        return $this;
    }

    /**
     * Get Nonce
     *
     * @return string|null
     */
    public function getNonce(): ?string {
        return $this->nonce;
    }

    /**
     * Set Operational Analytics Identifier
     *
     * @param string $operationalAnalyticsIdentifier
     * @return self
     */
    public function setOperationalAnalyticsIdentifier(string $operationalAnalyticsIdentifier): self {
        $this->operationalAnalyticsIdentifier = $operationalAnalyticsIdentifier;
        return $this;
    }

    /**
     * Get Operational Analytics Identifier
     *
     * @return string|null
     */
    public function getOperationalAnalyticsIdentifier(): ?string {
        return $this->operationalAnalyticsIdentifier;
    }

    /**
     * Set PSP Id
     *
     * @param string $pspId
     * @return self
     */
    public function setPspId(string $pspId): self {
        $this->pspId = $pspId;
        return $this;
    }

    /**
     * Get PSP Id
     *
     * @return string|null
     */
    public function getPspId(): ?string {
        return $this->pspId;
    }

    /**
     * Set Retries
     *
     * @param int $retries
     * @return self
     */
    public function setRetries(int $retries): self {
        $this->retries = $retries;
        return $this;
    }

    /**
     * Get Retries
     *
     * @return int|null
     */
    public function getRetries(): ?int {
        return $this->retries;
    }

    /**
     * Set Signature
     *
     * @param string $signature
     * @return self
     */
    public function setSignature(string $signature): self {
        $this->signature = $signature;
        return $this;
    }

    /**
     * Get Signature
     *
     * @return string|null
     */
    public function getSignature(): ?string {
        return $this->signature;
    }

}