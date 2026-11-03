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

namespace Acquired\Payments\Test\Unit\Model\Api;

use Acquired\Payments\Api\AcquiredCustomerRepositoryInterface;
use Acquired\Payments\Api\Data\AcquiredCustomerInterface;
use Acquired\Payments\Client\Customer as CustomerClient;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Model\AcquiredCustomer;
use Acquired\Payments\Model\AcquiredCustomerFactory;
use Acquired\Payments\Model\Api\CreateAcquiredCustomer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class CreateAcquiredCustomerTest extends TestCase
{
    private $customerClient;
    private $acquiredCustomerRepository;
    private $acquiredCustomerFactory;
    private $customerSession;
    private $createAcquiredCustomer;

    protected function setUp(): void
    {
        $this->customerClient = $this->createMock(CustomerClient::class);
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('getCustomer')->willReturn($this->customerClient);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getFirstname')->willReturn('Ann');
        $customer->method('getLastname')->willReturn('Example');
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->with(42)->willReturn($customer);

        $this->acquiredCustomerRepository = $this->createMock(AcquiredCustomerRepositoryInterface::class);
        $this->acquiredCustomerFactory = $this->createMock(AcquiredCustomerFactory::class);
        $this->customerSession = $this->createMock(CustomerSession::class);

        $this->createAcquiredCustomer = new CreateAcquiredCustomer(
            $this->customerSession,
            $gateway,
            $customerRepository,
            $this->acquiredCustomerFactory,
            $this->acquiredCustomerRepository
        );
    }

    public function testReturnsStoredIdWithoutCallingAcquired()
    {
        $stored = $this->createMock(AcquiredCustomerInterface::class);
        $stored->method('getAcquiredCustomerId')->willReturn('acq-1');
        $this->acquiredCustomerRepository->method('getByCustomerId')->with(42)->willReturn($stored);

        $this->customerClient->expects($this->never())->method('get');
        $this->customerClient->expects($this->never())->method('create');
        $this->customerSession->expects($this->never())->method('isLoggedIn');

        $this->assertSame('acq-1', $this->createAcquiredCustomer->getOrCreateAcquiredCustomerId(42));
    }

    public function testCreatesAndStoresCustomerWhenNoneStored()
    {
        $this->acquiredCustomerRepository->method('getByCustomerId')
            ->willThrowException(new NoSuchEntityException(__('Not found')));
        $this->customerClient->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => $data['reference'] === 42))
            ->willReturn(['customer_id' => 'acq-new']);

        $acquiredCustomer = $this->createMock(AcquiredCustomer::class);
        $acquiredCustomer->method('setCustomerId')->willReturnSelf();
        $acquiredCustomer->expects($this->once())->method('setAcquiredCustomerId')->with('acq-new')->willReturnSelf();
        $this->acquiredCustomerFactory->method('create')->willReturn($acquiredCustomer);
        $this->acquiredCustomerRepository->expects($this->once())->method('save')->with($acquiredCustomer);

        $this->assertSame('acq-new', $this->createAcquiredCustomer->getOrCreateAcquiredCustomerId(42));
    }
}
