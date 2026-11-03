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
use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Exception\Api\ApiCallException;
use Acquired\Payments\Model\StoreConfigResolver;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbstractClientTest extends TestCase
{
    private $cache;
    private $serializer;
    private $basicConfig;
    private $clientFactory;
    private $logger;
    private $storeConfigResolver;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->basicConfig = $this->createMock(Basic::class);
        $this->clientFactory = $this->createMock(ClientFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storeConfigResolver = $this->createMock(StoreConfigResolver::class);
        $this->storeConfigResolver->method('getStoreId')->willReturn(1);

        $this->clientFactory->method('create')->willReturn($this->createMock(GuzzleClient::class));
    }

    public function testAccessTokenIsCachedPerCredentials()
    {
        $this->basicConfig->method('getMode')->willReturn(true);
        $this->basicConfig->method('getApiId')->willReturnOnConsecutiveCalls('uk-app-id', 'fr-app-id');
        $this->basicConfig->method('getApiSecret')->willReturnOnConsecutiveCalls('uk-app-key', 'fr-app-key');

        $loadedKeys = [];
        $this->cache->method('load')->willReturnCallback(function ($key) use (&$loadedKeys) {
            $loadedKeys[] = $key;
            return 'token';
        });

        $client = new Transaction($this->cache, $this->serializer, $this->basicConfig, $this->clientFactory, $this->logger, $this->storeConfigResolver);
        $this->callIgnoringResponse(fn () => $client->get('transaction-1'));
        $this->callIgnoringResponse(fn () => $client->get('transaction-2'));

        $this->assertCount(2, $loadedKeys);
        $this->assertSame('acquired_access_token_live_1_' . hash('sha256', 'uk-app-id:uk-app-key'), $loadedKeys[0]);
        $this->assertSame('acquired_access_token_live_1_' . hash('sha256', 'fr-app-id:fr-app-key'), $loadedKeys[1]);
    }

    public function testTestModeUsesSeparateCacheKey()
    {
        $this->basicConfig->method('getMode')->willReturn(false);
        $this->basicConfig->method('getApiId')->willReturn('uk-app-id');
        $this->basicConfig->method('getApiSecret')->willReturn('uk-app-key');

        $this->cache->expects($this->once())
            ->method('load')
            ->with('acquired_access_token_test_1_' . hash('sha256', 'uk-app-id:uk-app-key'))
            ->willReturn('token');

        $client = new Transaction($this->cache, $this->serializer, $this->basicConfig, $this->clientFactory, $this->logger, $this->storeConfigResolver);
        $this->callIgnoringResponse(fn () => $client->get('transaction-1'));
    }

    private function callIgnoringResponse(callable $call): void
    {
        try {
            $call();
        } catch (ApiCallException) {
        }
    }
}
