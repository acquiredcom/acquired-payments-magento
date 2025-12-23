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

namespace Acquired\Payments\Block\Applepay;

use Magento\Framework\View\Element\Template;
use Magento\Catalog\Block\ShortcutInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Escaper;
use Magento\Checkout\Model\Session;
use Magento\Framework\Locale\Resolver;
use Magento\Framework\App\Request\Http;
use Acquired\Payments\Helper\Express as ExpressHelper;

class CartButton extends MinicartButton implements ShortcutInterface
{
    protected $_template = 'Acquired_Payments::applepay/applepay-cart-button.phtml';

    /**
     * @param Context $context
     * @param Escaper $escaper
     * @param Session $checkoutSession
     * @param Resolver $localeResolver
     * @param Http $request
     * @param ExpressHelper $expressHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        Escaper $escaper,
        Session $checkoutSession,
        Resolver $localeResolver,
        Http $request,
        private readonly ExpressHelper $expressHelper,
        array $data = []
    ) {
        parent::__construct($context, $escaper, $checkoutSession, $localeResolver, $request, $expressHelper, $data);
    }

    /**
     * Get alias for cart button
     */
    public function getAlias(): string
    {
        return 'acquired.payments.applepay.cart';
    }

    /**
     * Get button classes for cart
     */
    public function getButtonClasses(): string
    {
        $classes = [];
        $classes[] = 'acquired-payments-applepay-cart-button';
        $classes[] = 'apple-pay-button';
        $classes[] = 'apple-pay-button-text-buy';
        $classes[] = $this->getButtonStyle();

        return implode(' ', $classes);
    }

    /**
     * Check if enabled for cart
     */
    protected function _toHtml()
    {
        // Check if Apple Pay is enabled for cart
        if (!$this->expressHelper->isExpressMethodEnabled('applepay', 'cart')) {
            return '';
        }

        return Template::_toHtml();
    }
}