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

namespace Acquired\Payments\Controller\Applepay;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

class Transaction extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private JsonSerializer $json,
        private \Acquired\Payments\Helper\Express $express,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $raw = $this->getRequest()->getContent();
        $params = $raw ? $this->json->unserialize($raw) : [];

        try {
            $results = $this->express->placeOrder($params); 
            return $result->setData($results[0] ?? ['error' => true, 'message' => 'Unknown error']);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            return $result->setData(['error' => true, 'message' => 'Order failed. Please try again or proceed to regular checkout.']);
        }
    }
}
