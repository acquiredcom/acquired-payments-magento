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

namespace Acquired\Payments\Gateway\Command;

use Acquired\Payments\Observer\AcquiredCardDataAssignObserver;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;

/**
 * Storefront checkout places the order before payment; admin and multishipping orders arrive already paid
 */
class CardAuthorizeCommand implements CommandInterface
{
    /**
     * @param CommandInterface $orderFirstCommand
     * @param CommandInterface $paidCommand
     */
    public function __construct(
        private readonly CommandInterface $orderFirstCommand,
        private readonly CommandInterface $paidCommand
    ) {
    }

    /**
     * @param array $commandSubject
     * @return void
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        if ($payment->getAdditionalInformation(AcquiredCardDataAssignObserver::ORDER_FIRST)) {
            return $this->orderFirstCommand->execute($commandSubject);
        }

        return $this->paidCommand->execute($commandSubject);
    }
}
