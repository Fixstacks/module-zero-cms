<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;

class CmsBlock implements TypeInterface
{
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function load(int $id): object
    {
        return $this->blockRepository->getById($id);
    }

    public function title(object $entity): string
    {
        return (string) $entity->getTitle();
    }

    public function identifier(object $entity): string
    {
        return (string) $entity->getIdentifier();
    }

    public function storefrontUrl(object $entity, StoreInterface $store): string
    {
        return '';
    }

    public function backUrl(int $id): string
    {
        return $this->backendUrl->getUrl('cms/block/edit', ['block_id' => $id]);
    }

    public function menu(): string
    {
        return 'Magento_Cms::cms_block';
    }
}
