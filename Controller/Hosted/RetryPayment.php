<?php

declare(strict_types=1);

/**
 * Acquired Limited Payment module (https://acquired.com/)
 *
 * Copyright (c) 2023 Acquired.com (https://acquired.com/)
 * See LICENSE.txt for license details.
 */

namespace Acquired\Payments\Controller\Hosted;

use Exception;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Acquired\Payments\Controller\Hosted\Context as HostedContext;

/**
 * @class RetryPayment
 *
 * Retries payment based on a one time payment nonce
 */
class RetryPayment extends AbstractAction implements CsrfAwareActionInterface, HttpPostActionInterface
{

    /**
     * Executes the action to process hosted checkout retry of payment
     */
    public function execute()
    {
        try {
            $nonce = $this->getRequest()->getParam('nonce');

            $order = $this->getOrderFromNonce($nonce);
            $response = $this->getRedirectResponse($order);

            if ($response && $response['link_id']) {
                $order->getPayment()->setLastTransId($response['link_id']);
                $order->getPayment()->save();
            }

            $resultRedirect = $this->resultRedirectFactory->create();
            $redirectLink = $this->hostedContext->basicConfig->getRedirectUrl() . $response['link_id'];
            $resultRedirect->setUrl($redirectLink);

            return $resultRedirect;
        } catch (Exception $e) {
            $this->hostedContext->logger->critical(__('Error handling Hosted: %1', $e->getMessage()), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('Payment could not be retried at this point.'));

            return $this->_redirect('');
        }
    }

    protected function getRedirectResponse($order)
    {

        $amount = floatval($order->getPayment()->getAmountOrdered());
        $isMultishipping = $this->checkMultishipping($order);

        $customData = [];

        if ($order->getCustomerId()) {
            $customData['customer_id'] = $order->getCustomerId();
        }

        $requestData = $isMultishipping ? $this->processMultishipping($order) : $this->hostedContext->hostedCheckoutBuilder->getData((int) $order->getQuoteId(), $order->getIncrementId(), $amount, $customData);
        $requestData['transaction']['order_id'] = $requestData['transaction']['order_id'] . HostedContext::HOSTED_ORDER_ID_RETRY_IDENTIFIER . time();

        $response = $this->hostedContext->gateway->getPaymentLinks()->generateLinkId($requestData);

        return $response;
    }

    protected function checkMultishipping($order)
    {
        $multishippingItem = $this->hostedContext->multishippingService->getMultishippingByReservedOrderId($order->getIncrementId());
        return $multishippingItem !== null && $multishippingItem->getId();
    }

    protected function processMultishipping($order)
    {
        $multishippingItem = $this->hostedContext->multishippingService->getMultishippingByReservedOrderId($order->getIncrementId());
        $multishippingItems = $this->hostedContext->multishippingService->getMultishippingByTransactionId($multishippingItem->getAcquiredTransactionId());

        $incrementIds = [];
        foreach ($multishippingItems as $mi) {
            $incrementIds[] = $mi->getQuoteReservedId();
        }

        $multishippingResult = $this->hostedContext->multishippingService->processMultishippingOrdersByIncrementIds($incrementIds);

        foreach ($multishippingResult->getOrders() as $order) {
            $orderIds[] = $order->getIncrementId();
        }
        $customData = [
            'custom1' => 'multishipping order',
            'custom2' => implode(",", $orderIds)
        ];

        if ($multishippingResult->getCustomerId()) {
            $customData['customer_id'] = $multishippingResult->getCustomerId();
        }

        $requestData = $this->hostedContext->hostedCheckoutBuilder->getData((int) $order->getQuoteId(), $multishippingResult->getMultishippingOrderId(), $multishippingResult->getAmount(), $customData);

        return $requestData;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
