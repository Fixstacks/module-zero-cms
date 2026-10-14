<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Test\Unit\Model\Ai;

use PHPUnit\Framework\TestCase;
use Zero\Cms\Model\Ai\Schema;
use Zero\Cms\Model\Component\Registry;

class SchemaTest extends TestCase
{
    public function testEveryComponentBecomesAVariantWithTypedFields(): void
    {
        $registry = $this->createStub(Registry::class);
        $registry->method('getAll')->willReturn([
            'tiles' => ['label' => 'Tiles', 'description' => 'Grid', 'template' => 'x', 'fields' => [
                'columns' => ['type' => 'select', 'label' => 'Columns', 'options' => ['2' => '2', '3' => '3']],
                'items' => ['type' => 'repeater', 'label' => 'Tiles', 'max' => 12, 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'max' => 80],
                    'link' => ['type' => 'link', 'label' => 'Link'],
                ]],
            ]],
        ]);

        $schema = (new Schema($registry))->page();
        $variant = $schema['properties']['components']['items']['anyOf'][0];
        $data = $variant['properties']['data']['properties'];

        $this->assertSame('tiles', $variant['properties']['type']['const']);
        $this->assertSame(['2', '3'], $data['columns']['enum']);
        $this->assertSame(12, $data['items']['maxItems']);
        $this->assertSame(['title'], $data['items']['items']['required']);
        $this->assertSame(80, $data['items']['items']['properties']['title']['maxLength']);
        $this->assertSame('object', $data['items']['items']['properties']['link']['type']);
    }
}
