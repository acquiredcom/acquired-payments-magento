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

namespace Acquired\Payments\Controller\Webhook;

use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface as ResponseInterfaceAlias;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface as ResultInterfaceAlias;
use Magento\Framework\Serialize\SerializerInterface;
use Laminas\Http\Response as HttpResponse;
use Acquired\Payments\Model\Webhook\ProcessorFactory;
use Acquired\Payments\Exception\Webhook\WebhookVersionException;
use Acquired\Payments\Exception\Webhook\WebhookIntegrityException;
use Acquired\Payments\Exception\Webhook\WebhookOrderNotFoundException;

/**
 * @class Index
 *
 * Handles POST webhook requests by validating and routing them to the appropriate processor.
 */
class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{

    private const WEBHOOK_VERSION_KEY = 'Webhook-Version';
    private const WEBHOOK_HASH_KEY = 'Hash';
    private const STATUS_UPDATE_TYPE = 'status_update';
    private const STATUS_UPDATE_FIELDS = ['order_id', 'status', 'transaction_id'];

    /**
     * @param Context $context
     * @param ProcessorFactory $processorFactory
     * @param JsonFactory $resultJsonFactory
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ProcessorFactory $processorFactory,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Processes incoming POST requests for webhook notifications and generates a JSON response.
     *
     * @return Json|ResultInterfaceAlias|ResponseInterfaceAlias Returns a JSON response with the processing outcome.
     * @throws WebhookVersionException If the webhook version is not supported.
     * @throws WebhookIntegrityException If the integrity check of the webhook data fails.
     * @throws WebhookOrderNotFoundException If the webhook references an unknown order.
     * @throws InvalidArgumentException If any arguments do not meet the expected criteria.
     * @throws Exception For all other unforeseen errors that may occur during processing.
     */
    public function execute(): Json|ResultInterfaceAlias|ResponseInterfaceAlias
    {
        $result = $this->resultJsonFactory->create();

        try {
            $requestPayload = (string) $this->getRequest()->getContent();
            $webhookVersion = (string) $this->getRequest()->getHeader(self::WEBHOOK_VERSION_KEY);
            $webhookHash = (string) $this->getRequest()->getHeader(self::WEBHOOK_HASH_KEY);

            if ($webhookVersion === '' || $webhookHash === '') {
                throw new InvalidArgumentException((string) __('Missing webhook version or hash header.'));
            }

            $webhookData = $this->serializer->unserialize($requestPayload);

            if (
                !is_array($webhookData)
                || !isset($webhookData['webhook_type'])
                || !is_string($webhookData['webhook_type'])
                || !isset($webhookData['webhook_body'])
                || !is_array($webhookData['webhook_body'])
            ) {
                throw new InvalidArgumentException((string) __('Invalid webhook payload.'));
            }

            if ($webhookData['webhook_type'] === self::STATUS_UPDATE_TYPE) {
                foreach (self::STATUS_UPDATE_FIELDS as $field) {
                    if (!isset($webhookData['webhook_body'][$field]) || !is_string($webhookData['webhook_body'][$field])) {
                        throw new InvalidArgumentException((string) __('Invalid webhook payload.'));
                    }
                }
            }

            $this->logger->debug(
                __('Webhook %1 action request', $webhookData['webhook_type']),
                [
                    'payload' => $requestPayload,
                    'version' => $webhookVersion,
                    'hash' => $webhookHash
                ]
            );

            $processor = $this->processorFactory->create($webhookData['webhook_type']);
            $processorResponse = $processor->execute($webhookData, $webhookHash, $webhookVersion);

            if (!empty($processorResponse['retryable'])) {
                $this->setResponseData(
                    $result,
                    false,
                    (string) __('Webhook could not be processed yet, please retry.'),
                    $processorResponse,
                    HttpResponse::STATUS_CODE_503
                );
            } else {
                $this->setResponseData(
                    $result,
                    true,
                    (string) __('Webhook processed successfully.'),
                    $processorResponse
                );
            }

            $this->logger->debug(
                __('Webhook %1 process response', $webhookData['webhook_type']),
                [
                    'response' => $this->serializer->serialize($processorResponse)
                ]
            );
        } catch (WebhookVersionException | WebhookIntegrityException | WebhookOrderNotFoundException | InvalidArgumentException $e) {
            $this->logger->critical(__('Webhook process failed: %1', $e->getMessage()), ['exception' => $e]);
            $this->handleException($result, $e);
        } catch (Exception $e) {
            $this->logger->critical(__('Webhook process failed: %1', $e->getMessage()), ['exception' => $e]);
            $this->setResponseData(
                $result,
                false,
                $e->getMessage(),
                null,
                HttpResponse::STATUS_CODE_500
            );
        }

        return $result;
    }

    /**
     * Sets the JSON response data and HTTP status code for the webhook response.
     *
     * @param Json $result JSON result factory instance to set the response.
     * @param bool $success Indicates whether the webhook processing was successful.
     * @param string $message A message describing the outcome of the processing.
     * @param mixed|null $data Additional data to return in the response, if any.
     * @param int $httpStatusCode HTTP status code to set for the response. Defaults to 200 OK.
     * @return void
     */
    private function setResponseData(
        Json $result,
        bool $success,
        string $message,
        mixed $data = null,
        int $httpStatusCode = HttpResponse::STATUS_CODE_200,
    ): void {
        $result->setData([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);

        $result->setHttpResponseCode($httpStatusCode);
    }

    /**
     * Handles specific exceptions and sets the response accordingly.
     *
     * @param Json $result An instance of the JSON result factory to set the response data.
     * @param Exception $exception The caught exception that will determine the HTTP status code and message of the response.
     * @return void
     */
    private function handleException(Json $result, Exception $exception): void
    {
        $statusCode = match (true) {
            $exception instanceof WebhookVersionException => HttpResponse::STATUS_CODE_422,
            $exception instanceof WebhookIntegrityException => HttpResponse::STATUS_CODE_401,
            $exception instanceof WebhookOrderNotFoundException => HttpResponse::STATUS_CODE_404,
            $exception instanceof InvalidArgumentException => HttpResponse::STATUS_CODE_400,
            default => HttpResponse::STATUS_CODE_500,
        };

        $this->setResponseData($result, false, $exception->getMessage(), null, $statusCode);
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
