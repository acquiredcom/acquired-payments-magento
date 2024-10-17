<?php

declare(strict_types=1);
/**
 *
 * Acquired Limited Payment module (https://acquired.com/)
 *
 * Copyright (c) 2024 Acquired.com (https://acquired.com/)
 * See LICENSE.txt for license details.
 *
 *
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

/**
 * @class Index
 *
 * Handles POST webhook requests by validating and routing them to the appropriate processor.
 */
class Index extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{

    private const WEBHOOK_VERSION_KEY = 'Webhook-Version';
    private const WEBHOOK_HASH_KEY = 'Hash';

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
     * @throws InvalidArgumentException If any arguments do not meet the expected criteria.
     * @throws Exception For all other unforeseen errors that may occur during processing.
     */
    public function execute(): Json|ResultInterfaceAlias|ResponseInterfaceAlias
    {
        $result = $this->resultJsonFactory->create();

        try {
            $requestPayload = $this->getRequest()->getContent();
            $webhookVersion = $this->getRequest()->getHeader(self::WEBHOOK_VERSION_KEY);
            $webhookHash = $this->getRequest()->getHeader(self::WEBHOOK_HASH_KEY);
            $webhookData = $this->serializer->unserialize($requestPayload);

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

            $this->setResponseData(
                $result,
                true,
                (string) __('Webhook processed successfully.'),
                $processorResponse
            );

            $this->logger->debug(
                __('Webhook %1 process response', $webhookData['webhook_type']),
                [
                    'response' => $this->serializer->serialize($processorResponse)
                ]
            );
        } catch (WebhookVersionException | WebhookIntegrityException | InvalidArgumentException $e) {
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
