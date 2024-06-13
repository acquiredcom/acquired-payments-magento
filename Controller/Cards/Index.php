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

namespace Acquired\Payments\Controller\Cards;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index implements ActionInterface
{
    /**
     * Constructor
     *
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param Session $session
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
    	Context $context,
    	private readonly PageFactory $pageFactory,
    	private readonly Session $session,
    	private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * Execute
     *
     * @return ResponseInterface|ResultInterface|Page
     */
    public function execute()
    {
        if (!$this->session->isLoggedIn()) {
            $redirectFactory = $this->redirectFactory->create();
            return $redirectFactory->setPath('customer/account/login');
        }

        $pageFactory = $this->pageFactory->create();
        $pageFactory->getConfig()->getTitle()->set(__('Acquired Payments Cards'));

        return $pageFactory;
    }
}