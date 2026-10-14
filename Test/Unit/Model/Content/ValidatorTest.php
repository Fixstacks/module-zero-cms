<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Test\Unit\Model\Content;

use PHPUnit\Framework\TestCase;
use Zero\Cms\Model\Component\Registry;
use Zero\Cms\Model\Content\HtmlSanitizer;
use Zero\Cms\Model\Content\Validator;

class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $components = [
            'hero' => ['template' => 'x', 'fields' => [
                'heading' => ['type' => 'text', 'required' => true, 'max' => 20],
                'text' => ['type' => 'textarea'],
                'button' => ['type' => 'link'],
                'image' => ['type' => 'image'],
                'align' => ['type' => 'select', 'options' => ['start' => 'Start', 'center' => 'Center'], 'default' => 'start'],
                'dark' => ['type' => 'boolean'],
                'limit' => ['type' => 'number', 'min' => 1, 'max' => 24, 'default' => 8],
                'products' => ['type' => 'products'],
                'items' => ['type' => 'repeater', 'max' => 2, 'fields' => ['title' => ['type' => 'text']]],
                'ends_at' => ['type' => 'datetime'],
            ]],
            'article' => ['template' => 'x', 'fields' => [
                'body' => ['type' => 'markdown'],
                'html' => ['type' => 'html'],
            ]],
        ];
        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturnCallback(fn ($code) => $components[$code] ?? null);
        $this->validator = new Validator($registry, new HtmlSanitizer());
    }

    public function testUnknownComponentsAndFieldsAreDropped(): void
    {
        $clean = $this->validator->clean(['components' => [
            ['type' => 'script', 'data' => ['x' => 1]],
            ['type' => 'hero', 'data' => ['heading' => 'Hi', 'onclick' => 'alert(1)']],
            'not an array',
        ]]);

        $this->assertCount(1, $clean['components']);
        $this->assertSame('hero', $clean['components'][0]['type']);
        $this->assertArrayNotHasKey('onclick', $clean['components'][0]['data']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $clean['components'][0]['uid']);
    }

    public function testTextLosesMarkupAndIsCut(): void
    {
        $data = $this->data(['heading' => "<script>x</script>A very long heading that goes on", 'text' => "One\n\nTwo\u{0007}"]);

        $this->assertSame('xA very long heading', $data['heading']);
        $this->assertSame("One\n\nTwo", $data['text']);
    }

    public function testOnlySafeLinksAndMediaPaths(): void
    {
        foreach (['javascript:alert(1)', '//evil.example/x', 'data:text/html,x', 'http://a b'] as $bad) {
            $this->assertSame('', $this->data(['button' => ['url' => $bad, 'label' => 'x']])['button']['url'], $bad);
        }
        foreach (['https://example.com/a?b=1', '/women.html', '#top', 'mailto:hi@example.com', 'tel:+15555550100'] as $good) {
            $this->assertSame($good, $this->data(['button' => ['url' => $good]])['button']['url'], $good);
        }
        $this->assertSame('', $this->data(['image' => ['path' => '../../app/etc/env.php']])['image']['path']);
        $this->assertSame('zero/cms/a.jpg', $this->data(['image' => ['path' => '/zero/cms/a.jpg', 'alt' => 'A']])['image']['path']);
    }

    public function testSelectBooleanNumberProductsRepeater(): void
    {
        $data = $this->data([
            'align' => 'diagonal',
            'dark' => 'yes',
            'limit' => 500,
            'products' => ['source' => 'skus', 'skus' => 'WT09, <b>MJ01</b> ,,'],
            'items' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C'], 'junk'],
        ]);

        $this->assertSame('start', $data['align']);
        $this->assertTrue($data['dark']);
        $this->assertSame(24, $data['limit']);
        $this->assertSame(['source' => 'skus', 'category_id' => 0, 'skus' => ['WT09', 'bMJ01/b']], $data['products']);
        $this->assertSame([['title' => 'A'], ['title' => 'B']], $data['items']);
    }

    public function testMissingRequiredFieldsAreNamed(): void
    {
        $clean = $this->validator->clean(['components' => [['type' => 'hero', 'data' => []]]]);

        $this->assertSame(['heading'], $this->validator->missingRequired($clean['components'][0]));
    }

    public function testDatetimeIsAValidLocalMinute(): void
    {
        $this->assertSame('2026-10-16T18:00', $this->data(['ends_at' => '2026-10-16T18:00'])['ends_at']);
        $this->assertSame('2026-10-16T18:00', $this->data(['ends_at' => '2026-10-16T18:00:59'])['ends_at'], 'seconds dropped');
        $this->assertSame('', $this->data(['ends_at' => '2026-02-30T10:00'])['ends_at'], 'no such day');
        $this->assertSame('', $this->data(['ends_at' => '2026-10-16T24:00'])['ends_at']);
        $this->assertSame('', $this->data(['ends_at' => 'tomorrow'])['ends_at']);
        $this->assertSame('', $this->data(['ends_at' => ['2026-10-16T18:00']])['ends_at']);
    }

    private function data(array $data): array
    {
        return $this->validator->clean(['components' => [['type' => 'hero', 'data' => $data]]])['components'][0]['data'];
    }

    public function testMarkdownKeepsWhatWasTypedAndHtmlIsCleaned(): void
    {
        $clean = $this->validator->clean(['components' => [['type' => 'article', 'data' => [
            'body' => "## Heading\n\nUse `a < b` and <b>bold</b>\r\n- item",
            'html' => '<h1>Title</h1><p onclick="x()">Hi <script>alert(1)</script><a href="javascript:x()">link</a></p><img src="https://e.com/a.jpg" alt="A">',
        ]]]]);
        $data = $clean['components'][0]['data'];
        // Markdown is escaped when rendered, so markup characters stay as typed.
        $this->assertSame("## Heading\n\nUse `a < b` and <b>bold</b>\n- item", $data['body']);
        $this->assertStringNotContainsString('script', $data['html']);
        $this->assertStringNotContainsString('onclick', $data['html']);
        $this->assertStringNotContainsString('javascript', $data['html']);
        $this->assertStringContainsString('<h2>Title</h2>', $data['html']);
        $this->assertStringContainsString('src="https://e.com/a.jpg" alt="A"', $data['html']);
        $this->assertStringContainsString('loading="lazy"', $data['html']);
    }

    public function testShareSettingsAreKeptOnlyWhenSet(): void
    {
        $this->assertArrayNotHasKey('share', $this->validator->clean(['components' => [], 'share' => ['title' => ' ', 'image' => ['path' => '']]]));
        $clean = $this->validator->clean(['components' => [], 'share' => [
            'title' => '<b>Summer sale</b>', 'description' => str_repeat('x', 400), 'image' => ['path' => '/zero/cms/../etc/passwd.jpg', 'alt' => 'A'],
        ]]);
        $this->assertSame('Summer sale', $clean['share']['title']);
        $this->assertSame(300, mb_strlen($clean['share']['description']));
        $this->assertSame('', $clean['share']['image']['path']);
        $this->assertSame('zero/cms/2026/10/sale.jpg', $this->validator->clean(['share' => ['image' => ['path' => '/zero/cms/2026/10/sale.jpg', 'alt' => '']]])['share']['image']['path']);
    }
}
