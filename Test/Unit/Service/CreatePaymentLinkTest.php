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

namespace Acquired\Payments\Test\Unit\Service;

use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Client\PaymentLinks;
use Acquired\Payments\Exception\Api\ApiCallException;
use Acquired\Payments\Service\CreatePaymentLink;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CreatePaymentLinkTest extends TestCase
{
    private $paymentLinks;
    private $createPaymentLink;

    protected function setUp(): void
    {
        $this->paymentLinks = $this->createMock(PaymentLinks::class);
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('getPaymentLinks')->willReturn($this->paymentLinks);

        $this->createPaymentLink = new CreatePaymentLink($gateway, $this->createMock(LoggerInterface::class));
    }

    public function testRetriesWithoutCustomerWhenLinkIsRejected()
    {
        $payload = ['transaction' => ['order_id' => '000000007'], 'customer' => ['customer_id' => 'stale', 'billing' => []]];

        $this->paymentLinks->expects($this->exactly(2))
            ->method('generateLinkId')
            ->willReturnCallback(function (array $sent) {
                if (isset($sent['customer']['customer_id'])) {
                    throw new ApiCallException(__('Customer is invalid.'), null, 400);
                }
                $this->assertSame(['billing' => []], $sent['customer']);

                return ['status' => 'success', 'link_id' => 'link-1'];
            });

        $this->assertSame('link-1', $this->createPaymentLink->execute($payload)['link_id']);
    }

    public function testDoesNotRetryAfterServerError()
    {
        $this->paymentLinks->expects($this->once())
            ->method('generateLinkId')
            ->willThrowException(new ApiCallException(__('Bad gateway'), null, 502));

        $this->expectException(ApiCallException::class);

        $this->createPaymentLink->execute(['transaction' => ['order_id' => '000000007'], 'customer' => ['customer_id' => 'acq-1']]);
    }

    public function testDoesNotRetryAfterTimeout()
    {
        $this->paymentLinks->expects($this->once())
            ->method('generateLinkId')
            ->willThrowException(new ApiCallException(__('Unexpected API error: timed out')));

        $this->expectException(ApiCallException::class);

        $this->createPaymentLink->execute(['transaction' => ['order_id' => '000000007'], 'customer' => ['customer_id' => 'acq-1']]);
    }

    public function testDoesNotRetryWithoutCustomer()
    {
        $this->paymentLinks->expects($this->once())
            ->method('generateLinkId')
            ->willThrowException(new ApiCallException(__('Invalid amount')));

        $this->expectException(ApiCallException::class);

        $this->createPaymentLink->execute(['transaction' => ['order_id' => '000000007'], 'customer' => []]);
    }
}
