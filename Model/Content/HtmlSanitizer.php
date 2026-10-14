<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content;

/**
 * HTML fields (the "HTML" component, imported posts): HTML Purifier with an
 * allow-list of text formatting, links, images, tables. No scripts, styles,
 * event handlers, iframes or forms, so it is safe under the strict CSP. An h1
 * becomes h2 (the post title is the page's h1), figure/figcaption (HTML5,
 * which HTML Purifier doesn't know) become div/p; images load lazily.
 */
class HtmlSanitizer
{
    private const ALLOWED = 'p,br,h2,h3,h4,h5,h6,strong,b,em,i,u,s,sub,sup,small,'
        . 'a[href|title|rel],ul,ol[start],li,blockquote,cite,q,code,pre,hr,'
        . 'img[src|alt|width|height|title],'
        . 'table,caption,thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan],'
        . 'dl,dt,dd,abbr[title],span,div';

    private ?\HTMLPurifier $purifier = null;

    public function clean(string $html): string
    {
        $html = preg_replace(['#<(/?)h1\b#i', '#<(/?)figure\b#i', '#<(/?)figcaption\b#i'], ['<$1h2', '<$1div', '<$1p'], $html) ?? $html;
        $clean = $this->purifier()->purify($html);

        return preg_replace('#<img\b(?![^>]*\bloading=)#i', '<img loading="lazy" decoding="async"', $clean) ?? $clean;
    }

    private function purifier(): \HTMLPurifier
    {
        if ($this->purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', self::ALLOWED);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow', 'sponsored', 'ugc']);
            $config->set('AutoFormat.RemoveEmpty', true);
            $this->purifier = new \HTMLPurifier($config);
        }

        return $this->purifier;
    }
}
