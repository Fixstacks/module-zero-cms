<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Editor;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Preview\Drafts;
use Zero\Cms\Model\Content\Target\Pool;

/**
 * Stores the editor's unsaved content as a draft and answers with the signed
 * storefront link that renders it (Model\Preview\Drafts).
 */
class Preview extends AbstractEditor implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        Pool $targets,
        private readonly Drafts $drafts,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context, $targets);
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $store = $storeId ? $this->storeManager->getStore($storeId) : $this->storeManager->getDefaultStoreView();
        $query = $this->drafts->save($this->postedContent(), (int) $store->getId());
        $query['___store'] = $store->getCode();

        return $this->json(['url' => $store->getBaseUrl() . 'zero/preview/index/?' . http_build_query($query)]);
    }
}
