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
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Service\TransactionStatus;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Acquired\Payments\Exception\Command\RequestException;

class Refund implements ClientInterface
{

    /**
     * @param Gateway $gateway
     * @param TransactionStatus $transactionStatus
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly TransactionStatus $transactionStatus,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param TransferInterface $transferObject
     * @return array|null
     * @throws RequestException
     */
    public function placeRequest(TransferInterface $transferObject): ?array
    {
        $body = $transferObject->getBody();
        $this->logger->debug(
            __('Place Refund transaction request.'),
            [
                'body' => $body
            ]
        );

        try {
            if (!$this->transactionStatus->canRefundInvoice($body['transaction_id'])) {
                return [
                    'status' => 'declined',
                    'title' => __('ACQUIRED.com cannot refund transaction that is less than 24h old')
                ];
            }

            // check if transaction exists
            $transaction = $this->gateway->getTransaction()->get($body['transaction_id']);

            if (!isset($transaction['transaction_id'])) {
                throw new RequestException(__('Transaction not found.'));
            }

            // update check if 24 hours passed as we do not support refund on orders that are under 24hrs old
            if (isset($transaction['created']) && $transaction['created'] != '') {
                $createdTime = strtotime($transaction['created']);
                $checkTime = time() - (24 * 60 * 60);

                if ($createdTime < $checkTime) {
                    $response = $this->gateway->getTransaction()->refund(
                        $body['transaction_id'],
                        $body['reference']
                    );
                } else {
                    // order is not 24hours old, try void instead
                    if ($body['reference']['amount'] == $body['grand_total']) {
                        $response = $this->gateway->getTransaction()->void($body['transaction_id']);

                        if ($response['status'] === 'declined') {
                            throw new RequestException(__('Void transaction declined by payment gateway.'));
                        }
                    } else {
                        throw new RequestException(__('We currently cannot process partial refunds. Please try again tomorrow or opt for a full refund instead.'));
                    }
                }
            } else {
                throw new RequestException(__('Internal server error. Created date was not set.'));
            }

            $this->logger->debug(
                __('Refund transaction request placed successfully.'),
                [
                    'response' => $response
                ]
            );
        } catch (Exception $e) {
            $this->logger->critical(
                __('Refund transaction request failed: %1', $e->getMessage()),
                [
                    'exception' => $e
                ]
            );

            throw new RequestException(__($e->getMessage()));
        }

        return $response;
    }
}
