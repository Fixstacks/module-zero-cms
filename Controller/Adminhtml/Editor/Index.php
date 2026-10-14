<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Editor;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\Page;

/**
 * The Zero CMS editor for a CMS page, block or another editable type (type, id, store).
 */
class Index extends AbstractEditor implements HttpGetActionInterface
{
    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $target = $this->target();
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->resultRedirectFactory->create()->setPath('cms/page/index');
        }
        /** @var Page $page */
        $page = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_PAGE);
        $page->setActiveMenu($target['handler']->menu());
        $page->getConfig()->getTitle()->prepend(__('Zero CMS: %1', $target['title']));
        $editor = $page->getLayout()->getBlock('zero.cms.editor');
        if ($editor) {
            $editor->setData('target', $target);
        }

        return $page;
    }
}
