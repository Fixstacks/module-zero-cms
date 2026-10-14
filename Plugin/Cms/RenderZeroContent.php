<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Plugin\Cms;

use Magento\Cms\Block\Block as CmsBlockBlock;
use Magento\Cms\Block\Page as CmsPageBlock;
use Magento\Cms\Block\Widget\Block as CmsWidgetBlock;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Content\Repository;
use Zero\Cms\Model\Renderer;

/**
 * A CMS page or block that has Zero CMS content shows it instead of its
 * HTML content (which stays as it was, for when Zero CMS is removed). Pages
 * and blocks without Zero CMS content render as always.
 */
class RenderZeroContent
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Renderer $renderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly BlockResource $blockResource
    ) {
    }

    /**
     * @param AbstractBlock $subject
     * @param string $result
     * @return string
     */
    public function afterToHtml(AbstractBlock $subject, $result)
    {
        [$type, $id] = $this->entity($subject);
        if (!$id) {
            return $result;
        }
        $content = $this->repository->getForStore($type, $id, (int) $this->storeManager->getStore()->getId());
        if ($content === null || !$content['components']) {
            return $result;
        }

        return $this->renderer->render($content, $subject->getLayout());
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function entity(AbstractBlock $subject): array
    {
        if ($subject instanceof CmsPageBlock) {
            return [Repository::TYPE_PAGE, (int) $subject->getPage()->getId()];
        }
        $blockId = $subject instanceof CmsWidgetBlock || $subject instanceof CmsBlockBlock
            ? $subject->getData('block_id')
            : null;
        if ($blockId === null || $blockId === '') {
            return [Repository::TYPE_BLOCK, 0];
        }
        if (!is_numeric($blockId)) {
            $blockId = $this->blockResource->getConnection()->fetchOne(
                $this->blockResource->getConnection()->select()
                    ->from($this->blockResource->getMainTable(), ['block_id'])
                    ->where('identifier = ?', (string) $blockId)
                    ->limit(1)
            );
        }

        return [Repository::TYPE_BLOCK, (int) $blockId];
    }
}
