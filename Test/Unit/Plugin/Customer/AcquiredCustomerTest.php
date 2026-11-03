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

namespace Acquired\Payments\Test\Unit\Plugin\Customer;

use PHPUnit\Framework\TestCase;
use Acquired\Payments\Plugin\Customer\AcquiredCustomer;
use Acquired\Payments\Api\AcquiredCustomerRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Acquired\Payments\Api\Data\AcquiredCustomerInterface;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

class AcquiredCustomerTest extends TestCase
{
    private $loggerMock;
    private $extensionFactoryMock;
    private $acquiredCustomerRepositoryMock;
    private $customerRepositoryMock;
    private $customerMock;
    private $acquiredCustomerMock;
    private $extensionAttributesMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->extensionFactoryMock = $this->createMock(ExtensionAttributesFactory::class);
        $this->acquiredCustomerRepositoryMock = $this->createMock(AcquiredCustomerRepositoryInterface::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerMock = $this->createMock(CustomerInterface::class);
        $this->acquiredCustomerMock = $this->createMock(AcquiredCustomerInterface::class);
        $this->extensionAttributesMock = $this->createMock(AcquiredCustomerExtensionInterface::class);

        $this->acquiredCustomerRepositoryMock->method('getByCustomerId')
            ->willReturnCallback(function ($arg) {
                if ($arg == 123) {
                    // Return a value if the argument matches the condition for returning
                    return $this->acquiredCustomerMock;
                } else {
                    // Throw an exception if the argument matches the condition for throwing
                    throw new NoSuchEntityException(__("Some exception message"));
                }
            });

        // Mock getAcquiredCustomerId to return a specific value
        $this->extensionAttributesMock->method('getAcquiredCustomerId')
            ->willReturn('mock_id');

        $this->customerMock->method('getId')
            ->willReturn(123);

        $this->customerMock->method('getExtensionAttributes')
            ->willReturn($this->extensionAttributesMock);
        $this->extensionFactoryMock->method('create')
            ->willReturn($this->extensionAttributesMock);
    }

    /**
     * For customer which already has an acquired customer ID set, the afterGet method should not set it again
     *
     * @return void
     */
    public function testAfterGetDoesntSetAcquiredCustomerId()
    {
        $acquiredCustomer = new AcquiredCustomer($this->extensionFactoryMock, $this->acquiredCustomerRepositoryMock, $this->loggerMock);

        $this->extensionAttributesMock->expects($this->never())
            ->method('setAcquiredCustomerId')
            ->with($this->customerMock);

        $acquiredCustomer->afterGet($this->customerRepositoryMock, $this->customerMock);
    }

    /**
     * For customer which does not have an acquired customer ID set, the afterGet method should set it
     *
     * @return void
     */
    public function testAfterGetSetsAcquiredCustomerId()
    {
        $extensionFactoryMock = $this->createMock(ExtensionAttributesFactory::class);
        $extensionAttributesMock = $this->createMock(AcquiredCustomerExtensionInterface::class);
        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->method('getExtensionAttributes')
            ->willReturn($extensionAttributesMock);

        // Mock getAcquiredCustomerId to return a specific value
        $extensionAttributesMock->method('getAcquiredCustomerId')
            ->willReturn(null);

        $extensionFactoryMock->method('create')
            ->willReturn($extensionAttributesMock);

        $acquiredCustomer = new AcquiredCustomer($extensionFactoryMock, $this->acquiredCustomerRepositoryMock, $this->loggerMock);

        $extensionAttributesMock->expects($this->once())
            ->method('setAcquiredCustomerId');

        $acquiredCustomer->afterGet($this->customerRepositoryMock, $customerMock);
    }

    /**
     * For customer which already has an acquired customer ID set, the afterGetById method should not set it again
     *
     * @return void
     */
    public function testAfterGetByIdDoesntSetAcquiredCustomerId()
    {
        $acquiredCustomer = new AcquiredCustomer($this->extensionFactoryMock, $this->acquiredCustomerRepositoryMock, $this->loggerMock);

        $this->extensionAttributesMock->expects($this->never())
            ->method('setAcquiredCustomerId');

        $acquiredCustomer->afterGetById($this->customerRepositoryMock, $this->customerMock);
    }

    /**
     * For customer which does not have an acquired customer ID set, the afterGetById method should set it
     *
     * @return void
     */
    public function testAfterGetByIdSetsAcquiredCustomerId()
    {
        $extensionFactoryMock = $this->createMock(ExtensionAttributesFactory::class);
        $extensionAttributesMock = $this->createMock(AcquiredCustomerExtensionInterface::class);
        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->method('getExtensionAttributes')
            ->willReturn($extensionAttributesMock);

        // Mock getAcquiredCustomerId to return a specific value
        $extensionAttributesMock->method('getAcquiredCustomerId')
            ->willReturn(null);

        $extensionFactoryMock->method('create')
            ->willReturn($extensionAttributesMock);

        $extensionAttributesMock->expects($this->once())
            ->method('setAcquiredCustomerId');

        $acquiredCustomer = new AcquiredCustomer($extensionFactoryMock, $this->acquiredCustomerRepositoryMock, $this->loggerMock);


        $acquiredCustomer->afterGetById($this->customerRepositoryMock, $customerMock);
    }

    /**
     * For customer which doesnt exist in the acquired customer repository, the customer will never have an acquired customer ID set
     * to the extension attributes
     *
     * @return void
     */
    public function testSetAcquiredCustomerIdHandlesNoSuchEntityException()
    {
        $acquiredCustomer = new AcquiredCustomer($this->extensionFactoryMock, $this->acquiredCustomerRepositoryMock, $this->loggerMock);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(456);

        $customerMock->expects($this->never())
            ->method('setExtensionAttributes');

        $this->extensionFactoryMock->expects($this->never())
            ->method('create');

        // Expect logging of the exception message
        $acquiredCustomer->afterGet($this->customerRepositoryMock, $customerMock);
    }
}
