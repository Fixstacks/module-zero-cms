<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Test\Unit\Model\Content;

use PHPUnit\Framework\TestCase;
use Zero\Cms\Model\Content\Markdown;

class MarkdownTest extends TestCase
{
    public function testBlocksAndHeadingIds(): void
    {
        $result = (new Markdown())->render("# Why run\n\nText **bold** and *it*.\nSame paragraph.\n\n### Gear list\n- one\n- two\n\n1. a\n2. b\n\n> quoted\n\n---\n\n```\n<b>code</b>\n```\n## Why run");
        $this->assertSame(
            '<h2 id="why-run">Why run</h2><p>Text <strong>bold</strong> and <em>it</em>. Same paragraph.</p>'
            . '<h3 id="gear-list">Gear list</h3><ul><li>one</li><li>two</li></ul><ol><li>a</li><li>b</li></ol>'
            . '<blockquote><p>quoted</p></blockquote><hr><pre><code>&lt;b&gt;code&lt;/b&gt;</code></pre>'
            . '<h2 id="why-run-2">Why run</h2>',
            $result['html']
        );
        $this->assertSame(['why-run', 'gear-list', 'why-run-2'], array_column($result['headings'], 'id'));
        $this->assertSame([2, 3, 2], array_column($result['headings'], 'level'));
    }

    public function testEverythingElseIsEscaped(): void
    {
        $html = (new Markdown())->render('<script>alert(1)</script> <img src=x onerror=alert(1)> "quotes"')['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOnlySafeLinksAndImages(): void
    {
        $md = new Markdown();
        $this->assertSame(
            '<p><a href="https://x.com/a?b=1&amp;c=2" rel="noopener">out</a> <a href="/women.html">in</a> <a href="#gear">jump</a> <a href="mailto:a@b.c">mail</a></p>',
            $md->render('[out](https://x.com/a?b=1&c=2) [in](/women.html) [jump](#gear) [mail](mailto:a@b.c)')['html']
        );
        $this->assertSame('<p>bad</p>', $md->render('[bad](javascript:alert)')['html']);
        $this->assertSame('<p>bad</p>', $md->render('[bad](//evil.example)')['html']);
        $this->assertSame(
            '<p><img src="https://e.com/a.jpg" alt="A cat" loading="lazy" decoding="async"></p>',
            $md->render('![A cat](https://e.com/a.jpg)')['html']
        );
        $this->assertSame('<p>x</p>', $md->render('![x](data:image/png;base64,AAA)')['html']);
    }

    public function testInlineCodeIsNotFormatted(): void
    {
        $this->assertSame('<p><code>**not bold** &lt;tag&gt;</code> and snake_case_word</p>', (new Markdown())->render('`**not bold** <tag>` and snake_case_word')['html']);
    }

    public function testPlainText(): void
    {
        $this->assertSame('Heading Text bold and a link.', (new Markdown())->plain("## Heading\n\nText **bold** and [a link](/x)."));
    }
}
