<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;

class CmsPage implements TypeInterface
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function load(int $id): object
    {
        return $this->pageRepository->getById($id);
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
        return $store->getBaseUrl() . $entity->getIdentifier();
    }

    public function backUrl(int $id): string
    {
        return $this->backendUrl->getUrl('cms/page/edit', ['page_id' => $id]);
    }

    public function menu(): string
    {
        return 'Magento_Cms::cms_page';
    }
}
