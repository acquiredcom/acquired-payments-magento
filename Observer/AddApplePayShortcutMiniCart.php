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

namespace Acquired\Payments\Observer;

use Magento\Catalog\Block\ShortcutButtons;
use Magento\Checkout\Block\QuoteShortcutButtons;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Acquired\Payments\Block\Applepay\MinicartButton;
use Acquired\Payments\Helper\Express;

class AddApplePayShortcutMiniCart implements ObserverInterface
{
    public function __construct(
        private readonly Express $expressHelper
    ) {
    }

    public function execute(Observer $observer)
    {
        if (!$this->expressHelper->isExpressMethodEnabled('applepay')) {
            return;
        }

        if ($observer->getData('is_catalog_product')) {
            return;
        }

        $shortcutButtons = $observer->getEvent()->getContainer();
        $shortcut = $shortcutButtons->getLayout()->createBlock(
            MinicartButton::class,
            '',
            []
        );

        $shortcut->setIsCart(get_class($shortcutButtons) === ShortcutButtons::class);

        $shortcutButtons->addShortcut($shortcut, 0);
    }
}
