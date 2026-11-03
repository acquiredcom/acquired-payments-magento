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

namespace Acquired\Payments\Test\Unit\Gateway\Validator;

use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Gateway\Validator\TransactionDataIntegrityValidator;
use Exception;
use PHPUnit\Framework\TestCase;

class TransactionDataIntegrityValidatorTest extends TestCase
{
    private const API_SECRET = 'app-key';

    private $validator;

    protected function setUp(): void
    {
        $basicConfig = $this->createMock(Basic::class);
        $basicConfig->method('getApiSecret')->willReturn(self::API_SECRET);

        $this->validator = new TransactionDataIntegrityValidator($basicConfig);
    }

    public function testValidHashPasses()
    {
        $data = $this->responseData();
        $data['hash'] = $this->hashFor($data);

        $this->validator->validateIntegrity($data);

        $this->addToAssertionCount(1);
    }

    public function testInvalidHashIsRejected()
    {
        $data = $this->responseData();
        $data['hash'] = 'not-the-hash';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid data integrity');

        $this->validator->validateIntegrity($data);
    }

    public function testTamperedStatusIsRejected()
    {
        $data = $this->responseData();
        $data['hash'] = $this->hashFor($data);
        $data['status'] = 'success';

        $this->expectException(Exception::class);

        $this->validator->validateIntegrity($data);
    }

    public function testMissingFieldIsRejected()
    {
        $data = $this->responseData();
        unset($data['timestamp']);
        $data['hash'] = 'hash';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid data format');

        $this->validator->validateIntegrity($data);
    }

    private function responseData(): array
    {
        return [
            'status' => 'declined',
            'transaction_id' => 'a7e3fde5-5b83-44f4-9915-782bc7121717',
            'order_id' => '000000007',
            'timestamp' => '1700000000'
        ];
    }

    private function hashFor(array $data): string
    {
        $paramsHash = hash('sha256', $data['status'] . $data['transaction_id'] . $data['order_id'] . $data['timestamp']);

        return hash('sha256', $paramsHash . self::API_SECRET);
    }
}
