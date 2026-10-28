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

use Acquired\Payments\Ui\Method\CardProvider;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Card payment steps for the order the shopper has just placed. The order always comes from the shopper's
 * checkout session, never from the request.
 */
abstract class AbstractAction extends Action implements HttpPostActionInterface
{
    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        protected readonly JsonFactory $resultJsonFactory,
        protected readonly CheckoutSession $checkoutSession,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return Order|null
     */
    protected function getOrder(): ?Order
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId() || $order->getPayment()?->getMethod() !== CardProvider::CODE) {
            return null;
        }

        return $order;
    }

    /**
     * @param string $message
     * @return Json
     */
    protected function error(string $message): Json
    {
        return $this->resultJsonFactory->create()->setData(['error' => true, 'message' => $message]);
    }
}
