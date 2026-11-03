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

namespace Acquired\Payments\Test\Unit\Gateway\Request;

use PHPUnit\Framework\TestCase;
use Acquired\Payments\Gateway\Request\HostedCheckoutBuilder;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Acquired\Payments\Gateway\Config\Hosted\Config as HostedConfig;
use Acquired\Payments\Gateway\Config\Card\Config as CardConfig;
use Acquired\Payments\Api\Data\TransactionAddressDataInterface;
use Magento\Framework\Exception\LocalizedException;
use Acquired\Payments\Model\Api\CreateAcquiredCustomer;
use Acquired\Payments\Service\GetTransactionAddressData;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Quote\Model\Quote;
use Acquired\Payments\Exception\Command\BuilderException;
use Magento\Store\Model\Store;
use Acquired\Payments\Test\Unit\Gateway\Request\AbstractBuilderTestCase;

class HostedCheckoutBuilderTest extends AbstractBuilderTestCase
{
    private $logger;
    private $storeMock;
    private $storeManager;
    private $urlBuilder;
    private $hostedConfig;
    private $createAcquiredCustomer;
    private $getTransactionAddressData;
    private $quoteRepository;
    private $cardConfig;
    private $hostedCheckoutBuilder;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storeMock = $this->createMock(Store::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->hostedConfig = $this->createMock(HostedConfig::class);
        $this->createAcquiredCustomer = $this->createMock(CreateAcquiredCustomer::class);
        $this->getTransactionAddressData = $this->createMock(GetTransactionAddressData::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cardConfig = $this->createMock(CardConfig::class);

        $this->storeManager->method('getStore')->willReturn($this->storeMock);
        $this->storeMock->method('getCurrentCurrencyCode')->willReturn('GBP');

        $this->hostedCheckoutBuilder = new HostedCheckoutBuilder(
            $this->logger,
            $this->storeManager,
            $this->urlBuilder,
            $this->hostedConfig,
            $this->createAcquiredCustomer,
            $this->getTransactionAddressData,
            $this->quoteRepository,
            $this->cardConfig
        );
    }

    public function testBuildWithValidData()
    {
        $buildSubject = [
            'payment' => $this->createPaymentMock(),
            'amount' => 100.00
        ];

        $this->givenUrls('https://example.com/redirect', 'https://example.com/webhook');

        $result = $this->hostedCheckoutBuilder->build($buildSubject);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('transaction', $result);
        $this->assertArrayHasKey('redirect_url', $result);
        $this->assertArrayHasKey('webhook_url', $result);
    }

    public function testBuildWithMissingAmount()
    {
        $this->expectException(BuilderException::class);
        $this->expectExceptionMessage('Amount should be provided');

        $buildSubject = [
            'payment' => $this->createPaymentMock()
        ];

        $this->hostedCheckoutBuilder->build($buildSubject);

    }

    public function testBuildWithInvalidRedirectUrl()
    {
        $this->expectException(BuilderException::class);
        $this->expectExceptionMessage('Redirect URL must be HTTPS:');

        $buildSubject = [
            'payment' => $this->createPaymentMock(),
            'amount' => 100.00
        ];

        $this->givenUrls('http://example.com/redirect', 'https://example.com/webhook');

        $this->hostedCheckoutBuilder->build($buildSubject);
    }

    public function testBuildWithInvalidWebhookUrl()
    {
        $this->expectException(BuilderException::class);
        $this->expectExceptionMessage('Webhook URL must be HTTPS:');

        $buildSubject = [
            'payment' => $this->createPaymentMock(),
            'amount' => 100.00
        ];

        $this->givenUrls('https://example.com/redirect', 'http://example.com/webhook');

        $this->hostedCheckoutBuilder->build($buildSubject);
    }

    public function testTdsIsSentWithStoreConfigWhenActive()
    {
        $this->givenQuoteForStore(3);
        $this->cardConfig->method('isTdsActive')->with(3)->willReturn(true);
        $this->cardConfig->method('getTdsContactUrl')->with(3)->willReturn('https://example.com/contact');
        $this->cardConfig->method('getTdsChallengePreference')->with(3)->willReturn('challenge_preferred');

        $result = $this->hostedCheckoutBuilder->getData(1, '100000001', 10.0);

        $this->assertSame(
            ['is_active' => true, 'contact_url' => 'https://example.com/contact', 'challenge_preference' => 'challenge_preferred'],
            $result['tds']
        );
    }

    public function testTdsIsInactiveWhenDisabled()
    {
        $this->givenQuoteForStore(3);
        $this->cardConfig->method('isTdsActive')->willReturn(false);

        $result = $this->hostedCheckoutBuilder->getData(1, '100000001', 10.0);

        $this->assertSame(['is_active' => false], $result['tds']);
    }

    public function testTdsRequiresHttpsContactUrl()
    {
        $this->givenQuoteForStore(3);
        $this->cardConfig->method('isTdsActive')->willReturn(true);
        $this->cardConfig->method('getTdsContactUrl')->willReturn('http://example.com/contact');

        $this->expectException(BuilderException::class);

        $this->hostedCheckoutBuilder->getData(1, '100000001', 10.0);
    }

    public function testStoredAcquiredCustomerIdIsSent()
    {
        $this->givenQuoteForStore(3);
        $this->createAcquiredCustomer->expects($this->once())
            ->method('getOrCreateAcquiredCustomerId')
            ->with(42)
            ->willReturn('acq-customer-1');
        $this->createAcquiredCustomer->expects($this->never())->method('execute');

        $result = $this->hostedCheckoutBuilder->getData(1, '100000001', 10.0, ['customer_id' => '42']);

        $this->assertSame('acq-customer-1', $result['customer']['customer_id']);
    }

    public function testCustomerIdIsOmittedWhenUnavailable()
    {
        $this->givenQuoteForStore(3);
        $this->createAcquiredCustomer->method('getOrCreateAcquiredCustomerId')
            ->willThrowException(new LocalizedException(__('There was an issue processing request')));
        $this->logger->expects($this->once())->method('warning');

        $result = $this->hostedCheckoutBuilder->getData(1, '100000001', 10.0, ['customer_id' => '42']);

        $this->assertArrayNotHasKey('customer_id', $result['customer']);
    }

    private function givenQuoteForStore(int $storeId): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn($storeId);
        $this->quoteRepository->method('get')->willReturn($quote);

        $addressData = $this->createMock(TransactionAddressDataInterface::class);
        $addressData->method('getBilling')->willReturn([]);
        $this->getTransactionAddressData->method('execute')->willReturn($addressData);
    }

    private function givenUrls(string $redirectUrl, string $webhookUrl): void
    {
        $this->hostedConfig->method('getRedirectUrl')->willReturn('acquired/hosted/response');
        $this->hostedConfig->method('getWebhookUrl')->willReturn('acquired/webhook/index');
        $this->urlBuilder->method('getUrl')->willReturnMap([
            ['acquired/hosted/response', null, $redirectUrl],
            ['acquired/webhook/index', null, $webhookUrl],
        ]);
    }

    private function createPaymentMock()
    {
        $quoteMock = $this->createMock(Quote::class);
        $this->quoteRepository->method('get')->willReturn($quoteMock);
        return SubjectReader::readPayment([
            'payment' => $this->getPaymentMock(100.00, '123456', '100000001')
        ]);
    }
}
