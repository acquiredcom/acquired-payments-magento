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

namespace Acquired\Payments\Model\Api;

use Acquired\Payments\Api\AcquiredCustomerRepositoryInterface;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Model\AcquiredCustomerFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class CreateAcquiredCustomer
{

    /**
     * @param CustomerSession $customerSession
     * @param Gateway $gateway
     * @param CustomerRepositoryInterface $customerRepository
     * @param AcquiredCustomerFactory $acquiredCustomerFactory
     * @param AcquiredCustomerRepositoryInterface $acquiredCustomerRepository
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly Gateway $gateway,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AcquiredCustomerFactory $acquiredCustomerFactory,
        private readonly AcquiredCustomerRepositoryInterface $acquiredCustomerRepository
    ) {
    }

    /**
     * Initialize acquired customer
     *
     * @param int|null $customerId
     * @return array|null
     * @throws LocalizedException
     */
    public function execute($customerId = null): ?array
    {
        // fixes issue where customerId is sometimes passed as null
        $customerId = $customerId ?: $this->customerSession->getCustomer()->getId();

        if ($this->customerSession->isLoggedIn()) {
            try {
                $acquiredCustomerId = $this->acquiredCustomerRepository->getByCustomerId(
                    (int) ($customerId ? $customerId : $this->customerSession->getCustomerId())
                )->getAcquiredCustomerId();
                return $this->gateway->getCustomer()->get($acquiredCustomerId);
            } catch (NoSuchEntityException) {
                return $this->createCustomer($customerId);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get the stored Acquired customer ID, creating the Acquired customer if there is none yet.
     *
     * Unlike execute(), it needs no customer session and does not fetch the customer from Acquired.
     *
     * @param int $customerId
     * @return string|null
     * @throws LocalizedException
     */
    public function getOrCreateAcquiredCustomerId(int $customerId): ?string
    {
        try {
            $acquiredCustomerId = $this->acquiredCustomerRepository->getByCustomerId($customerId)->getAcquiredCustomerId();
        } catch (NoSuchEntityException) {
            $acquiredCustomerId = $this->createCustomer($customerId)['customer_id'] ?? null;
        }

        return $acquiredCustomerId ? (string) $acquiredCustomerId : null;
    }

    /**
     * Create acquired customer
     *
     * @param int|null $customerId
     * @return array
     * @throws LocalizedException
     */
    private function createCustomer($customerId = null): array
    {
        try {
            $customerData = $this->getCustomerData($customerId);
            $result = $this->gateway->getCustomer()->create($customerData);

            $acquiredCustomer = $this->acquiredCustomerFactory->create();
            $acquiredCustomer->setCustomerId((int) $customerId)
                ->setAcquiredCustomerId($result['customer_id']);
            $this->acquiredCustomerRepository->save($acquiredCustomer);

            return $result;
        } catch (CouldNotSaveException $exception) {
            throw new LocalizedException(__('Could not save customer'));
        } catch (\Exception $exception) {
            throw new LocalizedException(__('There was an issue processing request'));
        }
    }

    /**
     * Get customer data
     *
     * @param int|null $customerId
     * @return array
     */
    public function getCustomerData($customerId = null): array
    {
        if (!$customerId && !$this->customerSession->isLoggedIn()) {
            return [];
        }

        $customer = $customerId ? $this->customerRepository->getById($customerId) : $this->customerSession->getCustomer();
        $customerData = [
            'reference' => $customer->getId(),
            'first_name' => $customer->getFirstname(),
            'last_name' => $customer->getLastname()
        ];

        if ($customer->getDob()) {
            $customerData['dob'] = date('Y-m-d', strtotime($customer->getDob()));
        }

        return $customerData;
    }
}
