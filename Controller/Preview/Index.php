<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Preview;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Preview\Drafts;

/**
 * zero/preview/index: the editor's unsaved content, rendered in this store
 * view's theme (the editor's preview frame). Only with a valid signed link
 * (Model\Preview\Drafts); never cached or indexed.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly Drafts $drafts
    ) {
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $content = $this->drafts->load(
            (string) $this->request->getParam('draft', ''),
            (int) $this->storeManager->getStore()->getId(),
            (int) $this->request->getParam('expires', 0),
            (string) $this->request->getParam('token', '')
        );
        if ($content === null) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Preview'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $block = $page->getLayout()->getBlock('zero.cms.preview');
        if ($block) {
            $block->setData('content', $content);
        }

        return $page;
    }
}
