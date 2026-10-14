<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Editor;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\Cms\Model\Content\Repository;
use Zero\Cms\Model\Content\Target\Pool;

/**
 * A stored revision's content, to load into the editor (saved only when
 * the editor saves).
 */
class Revision extends AbstractEditor implements HttpGetActionInterface
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
        } catch (LocalizedException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
        $content = $this->repository->getRevision(
            (int) $this->getRequest()->getParam('revision', 0),
            $target['type'],
            $target['id'],
            $target['store']
        );

        return $content === null
            ? $this->json(['error' => (string) __('This version no longer exists.')], 404)
            : $this->json(['content' => $content]);
    }
}
