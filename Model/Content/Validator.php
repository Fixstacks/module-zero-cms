<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content;

use Zero\Cms\Model\Component\Registry;

/**
 * Cleans content against the component schemas: whatever comes from the
 * editor, a stored revision or an AI model, only known components and fields
 * of the right type and size survive. Templates still escape on output.
 *
 * Field types:
 * - text (one line, max 255 by default), textarea (max 5000)
 * - link {url, label}: http(s), site-relative (/...), #anchor, mailto:, tel:
 * - image {path, alt}: a path under pub/media (no "..")
 * - select (one of `options` keys), boolean, number (min/max)
 * - datetime: "YYYY-MM-DDTHH:MM", in the store's time zone
 * - products {source: category|skus, category_id, skus}
 * - repeater: a list of items with their own `fields`, up to `max`
 * - markdown: long text in Model\Content\Markdown's subset (escaped when rendered)
 * - html: HTML, cleaned by Model\Content\HtmlSanitizer when saved
 */
class Validator
{
    private const MAX_COMPONENTS = 60;

    public function __construct(
        private readonly Registry $registry,
        private readonly HtmlSanitizer $htmlSanitizer
    ) {
    }

    /**
     * @param mixed $content {"components": [{"type": "...", "data": {...}}, ...], "share": {...}}
     * @return array{components: array<int, array{uid: string, type: string, data: array}>, share?: array{image: array{path: string, alt: string}, title: string, description: string}}
     */
    public function clean($content): array
    {
        $components = [];
        foreach (array_slice((array) (is_array($content) ? ($content['components'] ?? []) : []), 0, self::MAX_COMPONENTS) as $item) {
            if (!is_array($item) || !is_string($item['type'] ?? null)) {
                continue;
            }
            $definition = $this->registry->get($item['type']);
            if ($definition === null) {
                continue;
            }
            $uid = is_string($item['uid'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $item['uid'])
                ? $item['uid']
                : bin2hex(random_bytes(6));
            $components[] = [
                'uid' => $uid,
                'type' => $item['type'],
                'data' => $this->fields((array) $definition['fields'], (array) ($item['data'] ?? [])),
            ];
        }

        $clean = ['components' => $components];
        $share = $this->share(is_array($content) ? ($content['share'] ?? null) : null);
        if ($share !== null) {
            $clean['share'] = $share;
        }

        return $clean;
    }

    /**
     * How the page looks when its link is shared (Zero_Core's social tags):
     * its own image, title and text; kept only when something is set.
     *
     * @param mixed $share
     * @return array{image: array{path: string, alt: string}, title: string, description: string}|null
     */
    private function share($share): ?array
    {
        if (!is_array($share)) {
            return null;
        }
        $image = $this->value(['type' => 'image'], $share['image'] ?? []);
        $clean = [
            'image' => $image,
            'title' => $this->string($share['title'] ?? '', 120),
            'description' => $this->string($share['description'] ?? '', 300),
        ];

        return $image['path'] !== '' || $clean['title'] !== '' || $clean['description'] !== '' ? $clean : null;
    }

    /**
     * Required fields a component is missing, by label (for the editor).
     *
     * @param array $component A cleaned component
     * @return string[]
     */
    public function missingRequired(array $component): array
    {
        $definition = $this->registry->get((string) ($component['type'] ?? ''));
        $missing = [];
        foreach ((array) ($definition['fields'] ?? []) as $name => $field) {
            if (!empty($field['required']) && $this->isEmpty($component['data'][$name] ?? null)) {
                $missing[] = (string) ($field['label'] ?? $name);
            }
        }

        return $missing;
    }

    private function fields(array $schema, array $data): array
    {
        $clean = [];
        foreach ($schema as $name => $field) {
            $clean[$name] = $this->value((array) $field, $data[$name] ?? null);
        }

        return $clean;
    }

    /**
     * @param array $field
     * @param mixed $value
     * @return mixed
     */
    private function value(array $field, $value)
    {
        switch ($field['type'] ?? 'text') {
            case 'textarea':
                return $this->string($value, (int) ($field['max'] ?? 5000), true);
            case 'markdown':
                // Escaped when rendered (Markdown::render), so "<" may stay.
                return $this->text($value, (int) ($field['max'] ?? 40000));
            case 'html':
                return $this->htmlSanitizer->clean($this->text($value, (int) ($field['max'] ?? 200000)));
            case 'link':
                $value = is_array($value) ? $value : [];
                return [
                    'url' => $this->url($value['url'] ?? ''),
                    'label' => $this->string($value['label'] ?? '', 120),
                ];
            case 'image':
                $value = is_array($value) ? $value : [];
                return ['path' => $this->mediaPath($value['path'] ?? ''), 'alt' => $this->string($value['alt'] ?? '', 255)];
            case 'select':
                $options = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                $value = is_scalar($value) ? (string) $value : '';
                return in_array($value, $options, true) ? $value : (string) ($field['default'] ?? ($options[0] ?? ''));
            case 'boolean':
                return $value === null ? (bool) ($field['default'] ?? false) : filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                $number = is_numeric($value) ? (int) $value : (int) ($field['default'] ?? 0);
                return max((int) ($field['min'] ?? PHP_INT_MIN), min((int) ($field['max'] ?? PHP_INT_MAX), $number));
            case 'datetime':
                return $this->datetime($value);
            case 'products':
                $value = is_array($value) ? $value : [];
                $skus = array_slice(array_values(array_filter(array_map(
                    static fn ($sku) => preg_replace('/[^\w\-.\/ ]/u', '', trim((string) $sku)),
                    is_array($value['skus'] ?? null) ? $value['skus'] : explode(',', (string) ($value['skus'] ?? ''))
                ))), 0, 24);
                return [
                    'source' => ($value['source'] ?? '') === 'skus' ? 'skus' : 'category',
                    'category_id' => max(0, (int) ($value['category_id'] ?? 0)),
                    'skus' => $skus,
                ];
            case 'repeater':
                $items = array_slice(array_values(array_filter((array) $value, 'is_array')), 0, (int) ($field['max'] ?? 20));
                return array_map(fn (array $item) => $this->fields((array) ($field['fields'] ?? []), $item), $items);
            case 'text':
            default:
                return $this->string($value, (int) ($field['max'] ?? 255));
        }
    }

    /**
     * Multi-line text as typed (markup kept): control characters removed.
     *
     * @param mixed $value
     */
    private function text($value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", (string) $value));

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @param mixed $value
     */
    private function string($value, int $max, bool $multiline = false): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = str_replace("\r\n", "\n", (string) $value);
        // No markup and no control characters; line breaks only where allowed.
        $value = strip_tags($value);
        $value = (string) preg_replace($multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u', '', $value);

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @param mixed $value
     */
    private function datetime($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/', $value, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
            return '';
        }

        return substr($m[0], 0, 16);
    }

    /**
     * @param mixed $url
     */
    private function url($url): string
    {
        $url = trim(is_string($url) ? $url : '');
        if ($url === '' || preg_match('/[\s<>"\'`]/', $url)) {
            return '';
        }
        if (preg_match('#^(https?://|/(?!/)|\#|mailto:|tel:)#i', $url)) {
            return mb_substr($url, 0, 2048);
        }

        return '';
    }

    /**
     * @param mixed $path
     */
    private function mediaPath($path): string
    {
        $path = ltrim(trim(is_string($path) ? $path : ''), '/');
        if ($path === '' || str_contains($path, '..') || !preg_match('#^[\w\-./]+\.(jpe?g|png|webp|gif|svg)$#i', $path)) {
            return '';
        }

        return $path;
    }

    /**
     * @param mixed $value
     */
    private function isEmpty($value): bool
    {
        if (is_array($value)) {
            return ($value['url'] ?? $value['path'] ?? null) !== null
                ? ($value['url'] ?? $value['path']) === ''
                : $value === [];
        }

        return $value === null || $value === '' ;
    }
}
