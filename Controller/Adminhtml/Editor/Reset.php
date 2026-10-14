<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Editor;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\Cms\Model\Content\Repository;
use Zero\Cms\Model\Content\Target\Pool;

/**
 * Removes a store view's own content: it shows the All Store Views content
 * again (for "All Store Views" itself: the page shows its HTML content).
 */
class Reset extends AbstractEditor implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        Pool $targets,
        private readonly Repository $repository
    ) {
        parent::__construct($context, $targets);
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $target = $this->target();
            $this->repository->delete($target['type'], $target['id'], $target['store'], $this->adminName());
        } catch (LocalizedException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json(['ok' => true]);
    }
}
