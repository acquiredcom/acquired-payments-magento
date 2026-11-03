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

namespace Acquired\Payments\Controller\Hosted;

use Psr\Log\LoggerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Acquired\Payments\Gateway\Request\HostedCheckoutBuilder;
use Acquired\Payments\Gateway\Http\Client\HostedCheckout as HostedCheckoutClient;
use Acquired\Payments\Gateway\Http\TransferFactory;
use Acquired\Payments\Gateway\Config\Basic as BasicConfig;
use Acquired\Payments\Service\MultishippingService;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Acquired\Payments\Client\Gateway;
use Acquired\Payments\Service\CreatePaymentLink;

/**
 * @class Context
 *
 * Provides dependencies to the hosted payment controllers.
 */
class Context
{

    const HOSTED_ORDER_ID_RETRY_IDENTIFIER = '-ACQR-';
    const ACQUIRED_ORDER_IDS = 'acquired_order_ids';

    public function __construct(
        public readonly PageFactory $pageFactory,
        public readonly SerializerInterface $serializer,
        public readonly LoggerInterface $logger,
        public readonly Registry $coreRegistry,
        public readonly OrderInterfaceFactory $orderFactory,
        public readonly CheckoutSession $checkoutSession,
        public readonly EncryptorInterface $encryptor,
        public readonly HostedCheckoutBuilder $hostedCheckoutBuilder,
        public readonly HostedCheckoutClient $hostedCheckoutClient,
        public readonly TransferFactory $transferFactory,
        public readonly BasicConfig $basicConfig,
        public readonly HttpContext $httpContext,
        public readonly MultishippingService $multishippingService,
        public readonly OrderCollectionFactory $orderCollectionFactory,
        public readonly Gateway $gateway,
        public readonly CreatePaymentLink $createPaymentLink
    ) {
    }
}
