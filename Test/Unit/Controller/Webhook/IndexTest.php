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

namespace Acquired\Payments\Test\Unit\Controller\Webhook;

use Acquired\Payments\Controller\Webhook\Index;
use Acquired\Payments\Exception\Webhook\WebhookIntegrityException;
use Acquired\Payments\Exception\Webhook\WebhookOrderNotFoundException;
use Acquired\Payments\Exception\Webhook\WebhookVersionException;
use Acquired\Payments\Model\Webhook\ProcessorFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

class IndexTest extends TestCase
{
    private const PAYLOAD = '{"webhook_type":"status_update","webhook_body":{"order_id":"000000007","status":"success","transaction_id":"t-1"}}';

    private $request;
    private $processorFactory;
    private $result;
    private $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $this->processorFactory = $this->createMock(ProcessorFactory::class);
        $this->result = $this->createMock(Json::class);
        $resultJsonFactory = $this->createMock(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($this->result);

        $this->controller = new Index(
            $context,
            $this->processorFactory,
            $resultJsonFactory,
            new JsonSerializer(),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testProcessedWebhookReturns200()
    {
        $this->givenRequest(self::PAYLOAD, '2', 'hash');
        $this->givenProcessor(null, ['success' => true, 'message' => 'ok']);

        $this->expectStatus(200);
        $this->controller->execute();
    }

    public function testMissingHeadersReturn400()
    {
        $this->givenRequest(self::PAYLOAD, false, false);
        $this->processorFactory->expects($this->never())->method('create');

        $this->expectStatus(400);
        $this->controller->execute();
    }

    public function testMissingHashHeaderReturns400()
    {
        $this->givenRequest(self::PAYLOAD, '2', false);
        $this->processorFactory->expects($this->never())->method('create');

        $this->expectStatus(400);
        $this->controller->execute();
    }

    public function testPayloadWithoutWebhookTypeReturns400()
    {
        $this->givenRequest('{"webhook_body":{}}', '2', 'hash');
        $this->processorFactory->expects($this->never())->method('create');

        $this->expectStatus(400);
        $this->controller->execute();
    }

    public function testInvalidHashReturns401()
    {
        $this->givenRequest(self::PAYLOAD, '2', 'bad');
        $this->givenProcessor(new WebhookIntegrityException(__('Failed integrity check.')));

        $this->expectStatus(401);
        $this->controller->execute();
    }

    public function testUnknownOrderReturns404()
    {
        $this->givenRequest(self::PAYLOAD, '2', 'hash');
        $this->givenProcessor(new WebhookOrderNotFoundException(__('Order #000000007 not found.')));

        $this->expectStatus(404);
        $this->controller->execute();
    }

    public function testVersionMismatchReturns422()
    {
        $this->givenRequest(self::PAYLOAD, '1', 'hash');
        $this->givenProcessor(new WebhookVersionException(__('Version 2 is required.')));

        $this->expectStatus(422);
        $this->controller->execute();
    }

    private function givenRequest(string $content, string|false $version, string|false $hash): void
    {
        $this->request->method('getContent')->willReturn($content);
        $this->request->method('getHeader')->willReturnMap([
            ['Webhook-Version', false, $version],
            ['Hash', false, $hash],
        ]);
    }

    private function givenProcessor(?Throwable $exception, array $response = []): void
    {
        $processor = new class($exception, $response) {
            public function __construct(private readonly ?Throwable $exception, private readonly array $response)
            {
            }

            public function execute(array $webhookData, string $webhookHash, string $webhookVersion): array
            {
                if ($this->exception) {
                    throw $this->exception;
                }

                return $this->response;
            }
        };

        $this->processorFactory->method('create')->with('status_update')->willReturn($processor);
    }

    private function expectStatus(int $statusCode): void
    {
        $this->result->expects($this->once())->method('setHttpResponseCode')->with($statusCode);
    }
}
