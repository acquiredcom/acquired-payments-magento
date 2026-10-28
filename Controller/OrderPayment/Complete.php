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

use Acquired\Payments\Exception\Payment\PaymentVerificationException;
use Acquired\Payments\Service\OrderPayment\Cancel as CancelOrderPayment;
use Acquired\Payments\Service\OrderPayment\Complete as CompleteOrderPayment;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

class Complete extends AbstractAction
{
    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param JsonSerializer $json
     * @param CompleteOrderPayment $completeOrderPayment
     * @param CancelOrderPayment $cancelOrderPayment
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        private readonly JsonSerializer $json,
        private readonly CompleteOrderPayment $completeOrderPayment,
        private readonly CancelOrderPayment $cancelOrderPayment
    ) {
        parent::__construct($context, $resultJsonFactory, $checkoutSession, $logger);
    }

    public function execute()
    {
        $order = $this->getOrder();
        if (!$order) {
            return $this->error((string)__('Your order could not be found. Please try again.'));
        }

        $raw = $this->getRequest()->getContent();
        $params = $raw ? $this->json->unserialize($raw) : [];
        $transactionId = (string)($params['transaction_id'] ?? '');
        $success = [
            'success' => true,
            'redirect' => $this->_url->getUrl('checkout/onepage/success')
        ];

        if ($transactionId === '') {
            return $this->error((string)__('The payment could not be confirmed. Please try again.'));
        }

        try {
            $this->completeOrderPayment->execute($order, $transactionId);
        } catch (PaymentVerificationException $e) {
            $this->logger->critical($e->getMessage());
            $this->cancelOrderPayment->execute(
                $order,
                (string)__('Payment could not be verified: %1', $e->getMessage()),
                true,
                $e->isPaymentTaken() ? $transactionId : null
            );

            return $this->error((string)__('Your payment could not be confirmed and has been cancelled. Please try again.'));
        } catch (\Throwable $e) {
            // Acquired reported the payment as successful, so the order stays in payment review for the webhook
            $this->logger->critical($e);
            $success['pending'] = true;
        }

        return $this->resultJsonFactory->create()->setData($success);
    }
}
