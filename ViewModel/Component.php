<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\ViewModel;

use Magento\CatalogWidget\Block\Product\ProductsList;
use Magento\CatalogWidget\Model\Rule\Condition\Combine;
use Magento\CatalogWidget\Model\Rule\Condition\Product as ProductCondition;
use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Widget\Helper\Conditions;
use Zero\Cms\Model\Content\Markdown;

/**
 * Helpers for Zero CMS component templates (given to each as `view`).
 */
class Component implements ArgumentInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Escaper $escaper,
        private readonly Conditions $conditions,
        private readonly TimezoneInterface $timezone,
        private readonly Markdown $markdown
    ) {
    }

    /**
     * A markdown field as HTML (escaped, headings with ids).
     */
    public function markdown(string $text): string
    {
        return $this->markdown->render($text)['html'];
    }

    /**
     * A datetime field ("YYYY-MM-DDTHH:MM", store time) as a Unix timestamp,
     * or null when empty.
     */
    public function timestamp(string $local): ?int
    {
        if ($local === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($local, new \DateTimeZone($this->timezone->getConfigTimezone())))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * A timestamp as the store shows dates: its locale and time zone.
     */
    public function formatDateTime(int $timestamp): string
    {
        return (string) $this->timezone->formatDateTime(
            (new \DateTime('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC')),
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * ISO 8601 with the store's offset, for <time datetime>.
     */
    public function isoDateTime(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone($this->timezone->getConfigTimezone()))
            ->format(\DateTimeInterface::ATOM);
    }

    /** URL of a path under pub/media (validated by Content\Validator), or ''. */
    public function mediaUrl(string $path): string
    {
        return $path === ''
            ? ''
            : rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/' . ltrim($path, '/');
    }

    /**
     * Plain text as escaped paragraphs: blank lines separate paragraphs,
     * single line breaks become <br>.
     */
    public function paragraphs(string $text, string $class = ''): string
    {
        $html = '';
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }
            $html .= sprintf(
                '<p%s>%s</p>',
                $class !== '' ? ' class="' . $this->escaper->escapeHtmlAttr($class) . '"' : '',
                nl2br($this->escaper->escapeHtml(trim($paragraph)))
            );
        }

        return $html;
    }

    /**
     * Product slider from a category or a list of SKUs, rendered by the
     * theme's catalog widget carousel (the same product cards as listings).
     *
     * @param array{source: string, category_id: int, skus: string[]} $products
     */
    public function productsHtml(LayoutInterface $layout, array $products, string $title, int $limit): string
    {
        $condition = $products['source'] === 'skus'
            ? ['attribute' => 'sku', 'operator' => '()', 'value' => implode(', ', $products['skus'])]
            : ['attribute' => 'category_ids', 'operator' => '==', 'value' => (string) $products['category_id']];
        if ($condition['value'] === '' || $condition['value'] === '0') {
            return '';
        }
        /** @var ProductsList $block */
        $block = $layout->createBlock(ProductsList::class);
        $block->setTemplate('Magento_CatalogWidget::product/widget/content/carousel.phtml')
            ->setData('title', $title)
            ->setData('products_count', max(1, min(24, $limit)))
            ->setData('show_pager', 0)
            ->setData('conditions_encoded', $this->conditions->encode([
                '1' => ['type' => Combine::class, 'aggregator' => 'all', 'value' => '1', 'new_child' => ''],
                '1--1' => ['type' => ProductCondition::class] + $condition,
            ]));

        return $block->toHtml();
    }
}
