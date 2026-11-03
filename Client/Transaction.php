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

namespace Acquired\Payments\Client;

class Transaction extends AbstractClient implements FilterableInterface
{
    use ValidateQueryParamsTrait;

    public const ORDER_ID = 'order_id';

    private const LIST_QUERY_FILTERS = [
        self::ORDER_ID,
        self::OFFSET,
        self::LIMIT,
        self::FILTER
    ];

    /**
     * List transactions, e.g. every attempt for one order ID.
     *
     * Sent without the Mid header so payments that settled on another MID of the company are found.
     *
     * @param array $queryParams
     * @return array|null
     * @throws \Exception
     */
    public function list(array $queryParams): ?array
    {
        self::validateQueryParams(array_keys($queryParams), self::LIST_QUERY_FILTERS);
        return $this->call('get', 'transactions', queryParams: $queryParams);
    }

    /**
     * Get transaction details
     *
     * @param string $transactionId
     * @param array $queryParams
     * @return array|null
     * @throws \Exception
     */
    public function get(string $transactionId, array $queryParams = ['filter' => '']): ?array
    {
        self::validateQueryParams(array_keys($queryParams), $this->getQueryFilters());
        return $this->call('get', "transactions/$transactionId", queryParams: $queryParams);
    }

    /**
     * Refund a payment
     *
     * @param string $transactionId
     * @param array $payload
     * @return array|null
     * @throws \Exception
     */
    public function refund(string $transactionId, array $payload): ?array
    {
        return $this->call('post', "transactions/$transactionId/refund", $payload);
    }

    /**
     * Void a transaction
     *
     * @param string $transactionId
     * @return array|null
     * @throws \Exception
     */
    public function void(string $transactionId): ?array
    {
        return $this->call('post', "transactions/$transactionId/void");
    }

    /**
     * Capture payment
     *
     * @param string $transactionId
     * @param array $payload
     * @return array|null
     * @throws \Exception
     */
    public function capture(string $transactionId, array $payload): ?array
    {
        return $this->call('post', "transactions/$transactionId/capture", $payload);
    }

    /**
     * Get linked transaction
     *
     * @param string $link
     * @return array|null
     * @throws \Exception
     */
    public function getLinkedTransaction(string $link): ?array
    {
        return $this->call('get', str_replace('/v1/', '', $link));
    }

    /**
     * @inheritDoc
     */
    public function getQueryFilters(): array
    {
        return [
            self::FILTER
        ];
    }
}
