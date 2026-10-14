<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Social;

use Magento\Cms\Model\Page;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Content\Repository;
use Zero\Cms\ViewModel\Component;
use Zero\Core\Model\Social\SourceInterface;
use Zero\Core\Model\Social\Text;

/**
 * A CMS page (the home page too) when shared: what was set under "Sharing"
 * in the Zero CMS editor, else its meta title and description, and the
 * first image among its Zero CMS components.
 */
class CmsPageSource implements SourceInterface
{
    public function __construct(
        private readonly Page $page,
        private readonly HttpRequest $request,
        private readonly Repository $repository,
        private readonly Component $component,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function describe(): ?array
    {
        if (!in_array($this->request->getFullActionName(), ['cms_page_view', 'cms_index_index'], true) || !$this->page->getId()) {
            return null;
        }
        $content = $this->repository->getForStore(Repository::TYPE_PAGE, (int) $this->page->getId(), (int) $this->storeManager->getStore()->getId());
        $share = $content['share'] ?? [];
        $image = (string) ($share['image']['path'] ?? '');
        $alt = (string) ($share['image']['alt'] ?? '');
        if ($image === '' && $content !== null) {
            [$image, $alt] = $this->firstImage($content['components']);
        }
        $isHome = $this->request->getFullActionName() === 'cms_index_index';

        return [
            'title' => (string) ($share['title'] ?? '') ?: (string) ($this->page->getMetaTitle() ?: $this->page->getTitle()),
            'description' => Text::first([
                (string) ($share['description'] ?? ''),
                (string) $this->page->getMetaDescription(),
                (string) $this->page->getContentHeading(),
            ]),
            'image' => $image !== '' ? $this->component->mediaUrl($image) : '',
            'image_alt' => $alt,
            'url' => $isHome
                ? $this->storeManager->getStore()->getBaseUrl()
                : $this->storeManager->getStore()->getBaseUrl() . ltrim((string) $this->page->getIdentifier(), '/'),
        ];
    }

    /**
     * The first image field value in the components, depth first.
     *
     * @return array{0: string, 1: string}
     */
    private function firstImage(array $value): array
    {
        if (isset($value['path'], $value['alt']) && is_string($value['path']) && $value['path'] !== '') {
            return [$value['path'], (string) $value['alt']];
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $found = $this->firstImage($child);
                if ($found[0] !== '') {
                    return $found;
                }
            }
        }

        return ['', ''];
    }
}
