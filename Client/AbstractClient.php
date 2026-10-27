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

use Throwable;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Acquired\Payments\Gateway\Config\Basic;
use Acquired\Payments\Model\StoreConfigResolver;
use Acquired\Payments\Exception\Api\ApiCallException;
use Acquired\Payments\Exception\Api\AuthorizationException;
use Acquired\Payments\Exception\Api\InvalidMethodException;
use Psr\Log\LoggerInterface;

abstract class AbstractClient
{
    private const METHODS = ['get', 'post', 'put'];

    private const API_URL = 'https://api.acquired.com/v1/';

    private const TEST_API_URL = 'https://test-api.acquired.com/v1/';

    private const CACHE_KEY = 'acquired_access_token';

    protected $excludedMidEndpoints = [
        'payment-links'
    ];

    /**
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     * @param Basic $basicConfig
     * @param ClientFactory $clientFactory
     * @param LoggerInterface $logger
     * @param StoreConfigResolver $storeConfigResolver
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly Basic $basicConfig,
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
        private readonly StoreConfigResolver $storeConfigResolver
    ) {
    }

    /**
     * Call API
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $payload
     * @param array|null $queryParams
     * @return array|null
     * @throws ApiCallException
     * @throws AuthorizationException
     * @throws InvalidMethodException
     */
    protected function call(
        string $method,
        string $endpoint,
        ?array $payload = null,
        ?array $queryParams = null
    ): ?array {

        $this->logger->debug(
            __('Preparing API call to %1 with method %2', $endpoint, $method),
            [
                'payload' => $payload,
                'queryParams' => $queryParams
            ]
        );

        if (!in_array($method, self::METHODS)) {
            $message = __('Method: %1 not supported', $method);
            $this->logger->critical($message);
            throw new InvalidMethodException($message);
        }

        try {
            $client = $this->clientFactory->create();
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAuthorizationToken(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ]
            ];

            // get company id from config
            $companyId = $this->basicConfig->getCompanyId();

            if ($companyId) {
                $options['headers']['Company-Id'] = $companyId;
            }

            // get mid from config
            $mid = $this->basicConfig->getMid();

            if ($mid && !in_array($endpoint, $this->excludedMidEndpoints) ) {
                $options['headers']['Mid'] = $mid;
            }

            if ($payload) {
                $options['body'] = $this->serializer->serialize($payload);
            }

            if ($queryParams) {
                $options['query'] = $queryParams;
            }

            $result = $client->$method($this->getEndpointUrl($endpoint), $options)->getBody()?->getContents();
            $this->logger->info(
                __('API call to %1 successful', $endpoint),
                [
                    'response' => $result
                ]
            );
        } catch (AuthorizationException $authorizationException) {
            $message = __('API authorization failed: %1', $authorizationException->getMessage());
            $this->logger->critical(
                $message,
                [
                    'endpoint' => $endpoint,
                    'exception' => $authorizationException
                ]
            );

            throw $authorizationException;
        } catch (GuzzleRequestException $requestException) {
            $message = __('API request failed: %1', $requestException->getMessage());
            $this->logger->critical(
                $message,
                [
                    'endpoint' => $endpoint,
                    'payload' => json_encode($payload),
                    'response' => $requestException->getResponse() ?
                        $requestException->getResponse()->getBody()->getContents() : null,
                    'exception' => $requestException
                ]
            );

            throw new ApiCallException($message);
        } catch (Throwable $e) {
            $message = __('Unexpected API error: %1', $e->getMessage());
            $this->logger->critical(
                $message,
                [
                    'endpoint' => $endpoint,
                    'exception' => $e
                ]
            );

            throw new ApiCallException($message);
        }

        return is_string($result) ? $this->serializer->unserialize($result) : null;
    }

    /**
     * Get authorization token
     *
     * @return string|bool
     * @throws AuthorizationException
     */
    private function getAuthorizationToken(): string|bool
    {
        $appId = (string) $this->basicConfig->getApiId();
        $appKey = (string) $this->basicConfig->getApiSecret();

        // scope the token to the store and credentials so websites with different merchant profiles don't share it
        $cacheKey = ($this->basicConfig->getMode() ? self::CACHE_KEY . '_live' : self::CACHE_KEY . '_test')
            . '_' . (int) $this->storeConfigResolver->getStoreId()
            . '_' . hash('sha256', $appId . ':' . $appKey);
        $token = $this->cache->load($cacheKey);

        if ($token === false) {
            try {
                $client = $this->clientFactory->create();
                $response = $client->post(
                    $this->getEndpointUrl('login'),
                    [
                        'body' => $this->serializer->serialize([
                            'app_id' => $appId,
                            'app_key' => $appKey
                        ])
                    ]
                )->getBody()?->getContents();

                $authorization = $this->serializer->unserialize($response);
                if (!isset($authorization['access_token'])) {
                    throw new AuthorizationException(__('Access token is missing.'));
                }


                $token = $authorization['access_token'];
                $this->cache->save($token, $cacheKey, [], $authorization['expires_in']);
            } catch (GuzzleRequestException $requestException) {
                throw new AuthorizationException(__($requestException->getMessage()));
            } catch (Throwable $e) {
                throw new AuthorizationException(__($e->getMessage()));
            }
        }

        return $token;
    }

    /**
     * Get endpoint url
     *
     * @param string $endpoint
     * @return string
     */
    private function getEndpointUrl(string $endpoint): string
    {
        $apiUrl = $this->basicConfig->getMode() ? self::API_URL : self::TEST_API_URL;
        return $apiUrl . $endpoint;
    }
}
