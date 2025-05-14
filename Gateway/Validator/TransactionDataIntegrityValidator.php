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

namespace Acquired\Payments\Gateway\Validator;

use Acquired\Payments\Gateway\Config\Basic;

/**
 * @class RequestBuilderValidator
 *
 * Validates the data integrity of a 3ds response from Acquired or a place order request payload from Magento
 */
class TransactionDataIntegrityValidator {

    private const ALGORITHM_KEY = 'sha256';

    public const STATUS_KEY = 'status';
    public const TRANSACTION_ID_KEY = 'transaction_id';
    public const ORDER_ID_KEY = 'order_id';
    public const TIMESTAMP_KEY = 'timestamp';
    public const HASH_KEY = 'hash';

    public function __construct(
        private readonly Basic $basicConfig
    ) { }

    public function validateIntegrity(array $data)
    {
        if(!isset(
            $data[self::STATUS_KEY],
            $data[self::TRANSACTION_ID_KEY],
            $data[self::ORDER_ID_KEY],
            $data[self::TIMESTAMP_KEY],
            $data[self::HASH_KEY]
        )) {
            throw new Exception(__('Invalid data format'));
        }

        $concatenatedParams = implode('', [
            $data[self::STATUS_KEY],
            $data[self::TRANSACTION_ID_KEY],
            $data[self::ORDER_ID_KEY],
            $data[self::TIMESTAMP_KEY]
        ]);

        $paramsHash = hash(self::ALGORITHM_KEY, $concatenatedParams);
        $generatedHash = hash(self::ALGORITHM_KEY, $paramsHash . $this->basicConfig->getApiSecret());

        if($generatedHash !== $data[self::HASH_KEY]) {
            throw new Exception(__('Invalid data integrity'));
        }
    }

}