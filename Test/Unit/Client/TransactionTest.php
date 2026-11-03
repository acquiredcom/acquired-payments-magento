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

namespace Acquired\Payments\Test\Unit\Client;

use Acquired\Payments\Client\Transaction;
use Acquired\Payments\Exception\Api\ValidationException;
use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Model\StoreConfigResolver;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TransactionTest extends TestCase
{
    private $history = [];
    private $transaction;

    protected function setUp(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('token');

        $basicConfig = $this->createMock(Basic::class);
        $basicConfig->method('getCompanyId')->willReturn('company-1');
        $basicConfig->method('getMid')->willReturn('mid-1');

        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"meta":{"total":0},"data":[]}'),
        ]));
        $handlerStack->push(Middleware::history($this->history));

        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn(new GuzzleClient(['handler' => $handlerStack]));

        $this->transaction = new Transaction(
            $cache,
            new Json(),
            $basicConfig,
            $clientFactory,
            $this->createMock(LoggerInterface::class),
            $this->createMock(StoreConfigResolver::class)
        );
    }

    public function testListIsSentWithoutMidHeader()
    {
        $result = $this->transaction->list([Transaction::ORDER_ID => '000000007-ACQR-1700000000', Transaction::LIMIT => 100]);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringEndsWith('/transactions', $request->getUri()->getPath());
        $this->assertSame('order_id=000000007-ACQR-1700000000&limit=100', $request->getUri()->getQuery());
        $this->assertFalse($request->hasHeader('Mid'));
        $this->assertSame('company-1', $request->getHeaderLine('Company-Id'));
        $this->assertSame(0, $result['meta']['total']);
    }

    public function testGetByIdStillSendsMidHeader()
    {
        $this->transaction->get('transaction-1');

        $this->assertSame('mid-1', $this->history[0]['request']->getHeaderLine('Mid'));
    }

    public function testListRejectsUnknownFilter()
    {
        $this->expectException(ValidationException::class);

        $this->transaction->list(['customer_id' => 'x']);
    }
}
