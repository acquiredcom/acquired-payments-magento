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

namespace Acquired\Payments\Block;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Framework\Serialize\Serializer\Serialize;

class Info extends ConfigurableInfo
{
    protected $_template = 'info/info.phtml';
    
    /**
     * @param Context $context
     * @param ConfigInterface $config
     * @param Repository $assetRepository
     */
    public function __construct(
        Context $context,
        ConfigInterface $config,
        private readonly Repository $assetRepository
    ) {
        parent::__construct($context, $config);
    }
    
    public function toPdf()
    {
        $this->setTemplate('Acquired_Payments::pdf/info.phtml');
        return $this->toHtml();
    }
    
    /**
     * Returns payment additional info value
     * @param object $payment
     * @param string $key
     * @return string
     */
    public function getPaymentInformation($payment, string $key): string
    {
        return $payment->getAdditionalInformation($key) ?: 'N/A';
    }
    
    /**
     * Returns card icon
     * @params string $card
     * @return string
     */
    public function getCardIcon(string $card): string
    {        
        switch (strtolower($card)) {
            case "visa":
                $image = $this->assetRepository->getUrl("Acquired_Payments::img/card-icons/visa.png");
                break;
            case "amex":
                $image = $this->assetRepository->getUrl("Acquired_Payments::img/card-icons/amex.png");
                break;
            case "mc":
                $image = $this->assetRepository->getUrl("Acquired_Payments::img/card-icons/mastercard.png");
                break;
            case "maestro":
                $image = $this->assetRepository->getUrl("Acquired_Payments::img/card-icons/maestro.png");
                break;
            case "mastercard":
                $image = $this->assetRepository->getUrl("Acquired_Payments::img/card-icons/maestro.png");
                break;
            default:
                $image = $this->assetRepository->getUrl("Acquired_Payments::img/card-icons/generic.png");
        }
        
        return $image; 
    }
}