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

namespace Acquired\Payments\Gateway\Http\Client;

use Exception;
use Psr\Log\LoggerInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Acquired\Payments\Service\CreatePaymentLink;
use Acquired\Payments\Exception\Command\RequestException;
use Magento\Sales\Api\OrderRepositoryInterface;

class HostedCheckout implements ClientInterface
{

    /**
     * @param CreatePaymentLink $createPaymentLink
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CreatePaymentLink $createPaymentLink,
        private readonly LoggerInterface $logger,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @param TransferInterface $transferObject
     * @return array
     * @throws RequestException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $body = $transferObject->getBody();
        $this->logger->debug(
            __('Place Sale transaction request.'),
            [
                'body' => $body
            ]
        );
        $quote = null;

        try {
            $order = $this->orderRepository->get($body['transaction']['order_id']);
            $quote = $order->getQuote();
        } catch (Exception $e) {
            if(isset($body['transaction'], $body['transaction']['order_id'])) {
                $this->logger->critical(
                    __('Order not found: %1', $body['transaction']['order_id']),
                    [
                        'exception' => $e
                    ]
                );
            }
        }

        if (!$quote || ($quote && !$quote->getIsMultiShipping())) {
            try {
                $response = $this->createPaymentLink->execute($body);
                $this->logger->debug(
                    __('Sale transaction request placed successfully.'),
                    [
                        'response' => $response
                    ]
                );
            } catch (Exception $e) {
                $this->logger->critical(
                    __('Sale transaction request failed: %1', $e->getMessage()),
                    [
                        'exception' => $e
                    ]
                );

                throw new RequestException(__($e->getMessage()));
            }
        } else {
            $response = [
                'status' => 'success',
                'link_id' => 'Quote-' . $quote->getId()
            ];
        }

        return $response;
    }
}
