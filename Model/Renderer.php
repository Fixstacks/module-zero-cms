<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Zero\Cms\Model\Component\Registry;
use Zero\Cms\Model\Content\Validator;
use Zero\Cms\ViewModel\Component as ComponentViewModel;

/**
 * Renders cleaned content: each component's template with its values as
 * `values`, plus the Component view model (media URLs, text to paragraphs,
 * products). Components are rendered on the server like any template, so
 * full-page cache, the strict CSP and translations apply as everywhere.
 */
class Renderer
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Validator $validator,
        private readonly ComponentViewModel $viewModel
    ) {
    }

    /**
     * @param mixed $content
     */
    public function render($content, LayoutInterface $layout): string
    {
        $html = '';
        foreach ($this->validator->clean($content)['components'] as $component) {
            $definition = $this->registry->get($component['type']);
            if ($definition === null) {
                continue;
            }
            /** @var Template $block */
            $block = $layout->createBlock(Template::class);
            $block->setTemplate($definition['template'])
                ->setData('values', $component['data'])
                ->setData('uid', $component['uid'])
                ->setData('view', $this->viewModel);
            $html .= sprintf(
                '<div class="zero-cms-component zero-cms-%s" data-zero-cms="%s">%s</div>',
                $component['type'],
                $component['uid'],
                $block->toHtml()
            );
        }

        return $html !== '' ? '<div class="zero-cms flex flex-col gap-12 sm:gap-16">' . $html . '</div>' : '';
    }
}
