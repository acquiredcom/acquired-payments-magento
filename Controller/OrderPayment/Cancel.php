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

namespace Acquired\Payments\Controller\OrderPayment;

use Acquired\Payments\Service\OrderPayment\Cancel as CancelOrderPayment;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class Cancel extends AbstractAction
{
    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param CancelOrderPayment $cancelOrderPayment
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        private readonly CancelOrderPayment $cancelOrderPayment
    ) {
        parent::__construct($context, $resultJsonFactory, $checkoutSession, $logger);
    }

    public function execute()
    {
        $order = $this->getOrder();
        if (!$order) {
            return $this->error((string)__('Your order could not be found.'));
        }

        try {
            $cancelled = $this->cancelOrderPayment->execute(
                $order,
                (string)__('Payment was not completed in the checkout.'),
                true
            );
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            return $this->error((string)__('Your order could not be cancelled. Please contact us before trying again.'));
        }

        return $this->resultJsonFactory->create()->setData(['success' => true, 'cancelled' => $cancelled]);
    }
}
