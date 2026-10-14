<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Ai;

use Zero\Cms\Model\Component\Registry;

/**
 * JSON Schema of page content built from the component registry, for the
 * model's structured answers. Whatever comes back is still cleaned by
 * Content\Validator before it reaches the editor.
 */
class Schema
{
    public function __construct(
        private readonly Registry $registry
    ) {
    }

    public function page(): array
    {
        $variants = [];
        foreach ($this->registry->getAll() as $code => $definition) {
            $variants[] = [
                'type' => 'object',
                'description' => $definition['label'] . ': ' . $definition['description'],
                'properties' => [
                    'type' => ['type' => 'string', 'const' => $code],
                    'data' => $this->object((array) $definition['fields']),
                ],
                'required' => ['type', 'data'],
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'components' => [
                    'type' => 'array',
                    'description' => 'The page, top to bottom.',
                    'items' => ['anyOf' => $variants],
                ],
            ],
            'required' => ['components'],
        ];
    }

    private function object(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $name => $field) {
            $properties[$name] = $this->field((array) $field);
            if (!empty($field['required'])) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function field(array $field): array
    {
        $label = (string) ($field['label'] ?? '');
        switch ($field['type'] ?? 'text') {
            case 'textarea':
                return ['type' => 'string', 'description' => $label . ' (plain text; a blank line separates paragraphs)', 'maxLength' => (int) ($field['max'] ?? 5000)];
            case 'markdown':
                return ['type' => 'string', 'description' => $label . ' (Markdown: "## " and "### " headings, "- " lists, **bold**, [links](/path), a blank line between paragraphs; no "# " heading)', 'maxLength' => (int) ($field['max'] ?? 40000)];
            case 'html':
                return ['type' => 'string', 'description' => $label . ' (HTML: keep every tag and attribute exactly, change only the text)', 'maxLength' => (int) ($field['max'] ?? 200000)];
            case 'link':
                return ['type' => 'object', 'description' => $label, 'properties' => [
                    'url' => ['type' => 'string', 'description' => 'A store path like /women.html, or https://...'],
                    'label' => ['type' => 'string', 'maxLength' => 120],
                ]];
            case 'image':
                return ['type' => 'object', 'description' => $label . ' (leave path empty: images are added by the editor; alt describes the wanted image)', 'properties' => [
                    'path' => ['type' => 'string'],
                    'alt' => ['type' => 'string', 'maxLength' => 255],
                ]];
            case 'select':
                return ['type' => 'string', 'description' => $label, 'enum' => array_map('strval', array_keys((array) ($field['options'] ?? [])))];
            case 'boolean':
                return ['type' => 'boolean', 'description' => $label];
            case 'number':
                return array_filter(['type' => 'integer', 'description' => $label, 'minimum' => $field['min'] ?? null, 'maximum' => $field['max'] ?? null], static fn ($v) => $v !== null);
            case 'datetime':
                return ['type' => 'string', 'description' => $label . ' (store time, YYYY-MM-DDTHH:MM)', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$'];
            case 'products':
                return ['type' => 'object', 'description' => $label, 'properties' => [
                    'source' => ['type' => 'string', 'enum' => ['category', 'skus']],
                    'category_id' => ['type' => 'integer', 'description' => 'One of the category ids given'],
                    'skus' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]];
            case 'repeater':
                return ['type' => 'array', 'description' => $label, 'maxItems' => (int) ($field['max'] ?? 20), 'items' => $this->object((array) ($field['fields'] ?? []))];
            case 'text':
            default:
                return ['type' => 'string', 'description' => $label, 'maxLength' => (int) ($field['max'] ?? 255)];
        }
    }
}
