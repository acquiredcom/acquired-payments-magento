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

namespace Acquired\Payments\Model\Api;

use Exception;
use Psr\Log\LoggerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Acquired\Payments\Client\MerchantSession as MerchantSessionClient;
use Acquired\Payments\Api\Data\ApplePaySessionDataInterface;
use Acquired\Payments\Model\Api\ApplePaySession;
use Acquired\Payments\Helper\Express;

class ApplePay
{

    /**
     * @param MerchantSessionClient $merchantSessionClient
     * @param LoggerInterface $logger
     * @param UrlInterface $url
     * @param RequestInterface $request
     * @param Express $express
     */
    public function __construct(
        private readonly MerchantSessionClient $merchantSessionClient,
        private readonly LoggerInterface $logger,
        private readonly UrlInterface $url,
        private readonly RequestInterface $request,
        private readonly Express $express
    ) {}

    /**
     * Creates a Merchant Session for ApplePay
     *
     * @return ApplePaySessionDataInterface
     */
    public function startSession(): ApplePaySessionDataInterface
    {
        $requestBody = $this->request->getContent();
        $params = json_decode($requestBody, true);

        $payload = [
            'domain' => parse_url($this->url->getBaseUrl(), PHP_URL_HOST),
            'display_name' => $this->express->getStoreName(),
            'validation_url' => empty($params['validationURL'])
                ? 'https://apple-pay-gateway.apple.com/paymentservices/startSession'
                : $params['validationURL']
        ];

        $response = $this->merchantSessionClient->applePay($payload);

        if (isset($response['merchant_session'])) {
            $data = json_decode(base64_decode($response['merchant_session']), true);
            $result = new ApplePaySession($data);
            return $result;
        }

        throw new Exception('Failed to start Apple Pay session');
    }

    /**
     * Processes a ApplePay transaction
     *
     * @return void
     */
    public function execute()
    {
        $requestBody = $this->request->getContent();
        $params = json_decode($requestBody, true);

        return $this->express->placeOrder($params);
    }
}
