<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content;

/**
 * Markdown fields (the "Article" component): a small, safe Markdown. Everything is
 * escaped first, then this subset becomes HTML (nothing else can):
 *
 *   ## Heading / ### Subheading     (# is an h2 too: the post title is the h1)
 *   - item / * item / 1. item       lists
 *   > quote                         blockquote
 *   ---                             a rule
 *   ```  code  ```                  a code block
 *   **bold**  *italic*  `code`  [text](https://…)  ![alt](https://…)
 *
 * Headings get ids (for the table of contents and links to a section).
 * Links: http(s), mailto, site-relative and #anchors only; other sites'
 * links open as they are, with rel="noopener".
 */
class Markdown
{
    /** @var array<string, int> */
    private array $usedIds = [];

    /**
     * @return array{html: string, headings: array<int, array{level: int, id: string, text: string}>}
     */
    public function render(string $markdown, string $idPrefix = ''): array
    {
        $this->usedIds = [];
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = '';
        $headings = [];
        $paragraph = [];
        $list = null; // ['ul'|'ol', items[]]
        $quote = [];
        $code = null;

        $flush = function () use (&$html, &$paragraph, &$list, &$quote): void {
            if ($paragraph) {
                $html .= '<p>' . $this->inline(implode(' ', array_map('trim', $paragraph))) . '</p>';
                $paragraph = [];
            }
            if ($list !== null) {
                $html .= '<' . $list[0] . '>';
                foreach ($list[1] as $item) {
                    $html .= '<li>' . $this->inline($item) . '</li>';
                }
                $html .= '</' . $list[0] . '>';
                $list = null;
            }
            if ($quote) {
                $html .= '<blockquote><p>' . $this->inline(implode(' ', $quote)) . '</p></blockquote>';
                $quote = [];
            }
        };

        foreach ($lines as $line) {
            if ($code !== null) {
                if (preg_match('/^\s*```/', $line)) {
                    $html .= '<pre><code>' . $this->escape(implode("\n", $code)) . '</code></pre>';
                    $code = null;
                } else {
                    $code[] = $line;
                }
                continue;
            }
            if (preg_match('/^\s*```/', $line)) {
                $flush();
                $code = [];
                continue;
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                $flush();
                $level = max(2, strlen($m[1]) + (strlen($m[1]) === 1 ? 1 : 0));
                $text = trim($m[2]);
                $id = $this->id($idPrefix . $text);
                $headings[] = ['level' => $level, 'id' => $id, 'text' => $this->plain($text)];
                $html .= sprintf('<h%d id="%s">%s</h%1$d>', $level, $id, $this->inline($text));
                continue;
            }
            if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $flush();
                $html .= '<hr>';
                continue;
            }
            if (preg_match('/^\s*>\s?(.*)$/u', $line, $m)) {
                if (!$quote) {
                    $flush();
                }
                $quote[] = trim($m[1]);
                continue;
            }
            if (preg_match('/^\s*(?:[-*+]|(\d+)[.)])\s+(.+)$/u', $line, $m)) {
                $type = $m[1] !== '' ? 'ol' : 'ul';
                if ($list === null || $list[0] !== $type) {
                    $flush();
                    $list = [$type, []];
                }
                $list[1][] = trim($m[2]);
                continue;
            }
            if ($list !== null && preg_match('/^\s{2,}(.+)$/u', $line, $m)) {
                $list[1][count($list[1]) - 1] .= ' ' . trim($m[1]); // a wrapped list item
                continue;
            }
            if ($quote || $list !== null) {
                $flush();
            }
            $paragraph[] = $line;
        }
        if ($code !== null) {
            $html .= '<pre><code>' . $this->escape(implode("\n", $code)) . '</code></pre>';
        }
        $flush();

        return ['html' => $html, 'headings' => $headings];
    }

    /** Text without Markdown, for excerpts and reading time. */
    public function plain(string $markdown): string
    {
        $text = preg_replace(['/!\[([^\]]*)\]\([^)]*\)/u', '/\[([^\]]+)\]\([^)]*\)/u', '/[*_`>#]+/u'], ['$1', '$1', ''], $markdown);

        return trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    }

    private function inline(string $text): string
    {
        $codes = [];
        // Inline code first: nothing inside it is formatted.
        $text = preg_replace_callback('/`([^`]+)`/u', function ($m) use (&$codes) {
            $codes[] = '<code>' . $this->escape($m[1]) . '</code>';

            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;
        $text = $this->escape($text);
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/u', function ($m) {
            $url = $this->safeUrl(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5), true);

            return $url === null ? $m[1] : sprintf('<img src="%s" alt="%s" loading="lazy" decoding="async">', $this->escape($url), $m[1]);
        }, $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) {
            $url = $this->safeUrl(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5), false);
            if ($url === null) {
                return $m[1];
            }
            $external = (bool) preg_match('#^https?://#i', $url);

            return sprintf('<a href="%s"%s>%s</a>', $this->escape($url), $external ? ' rel="noopener"' : '', $m[1]);
        }, $text) ?? $text;
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![_\w])_(?=\S)(.+?)(?<=\S)_(?![_\w])/u', '<em>$1</em>', $text) ?? $text;

        return preg_replace_callback("/\x00(\d+)\x00/", static fn ($m) => $codes[(int) $m[1]] ?? '', $text) ?? $text;
    }

    private function safeUrl(string $url, bool $image): ?string
    {
        if (preg_match('#^https?://#i', $url) || preg_match('#^/(?!/)#', $url)) {
            return $url;
        }
        if (!$image && (preg_match('#^mailto:[^\s]+$#i', $url) || preg_match('/^#[\w-]+$/u', $url))) {
            return $url;
        }

        return null;
    }

    private function id(string $text): string
    {
        $id = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $this->plain($text)), '-'));
        $id = $id !== '' ? mb_substr($id, 0, 60) : 'section';
        $this->usedIds[$id] = ($this->usedIds[$id] ?? 0) + 1;

        return $this->usedIds[$id] > 1 ? $id . '-' . $this->usedIds[$id] : $id;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
