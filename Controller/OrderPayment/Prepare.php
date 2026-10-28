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

use Acquired\Payments\Service\OrderPayment\Prepare as PrepareOrderPayment;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class Prepare extends AbstractAction
{
    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param PrepareOrderPayment $prepareOrderPayment
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        private readonly PrepareOrderPayment $prepareOrderPayment
    ) {
        parent::__construct($context, $resultJsonFactory, $checkoutSession, $logger);
    }

    public function execute()
    {
        $order = $this->getOrder();
        if (!$order || $order->getState() !== Order::STATE_PAYMENT_REVIEW) {
            return $this->error((string)__('Your order could not be found. Please try again.'));
        }

        try {
            $confirmParams = $this->prepareOrderPayment->execute($order);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            return $this->error((string)__('There was an issue confirming the payment.'));
        }

        return $this->resultJsonFactory->create()->setData(['confirm_params' => $confirmParams]);
    }
}
