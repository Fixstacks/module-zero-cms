<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Block;

use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;
use Zero\Cms\Model\Renderer;

/**
 * The preview page's content: the draft's components (Controller\Preview\Index).
 */
class Preview extends AbstractBlock
{
    public function __construct(
        Context $context,
        private readonly Renderer $renderer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        return $this->renderer->render($this->getData('content'), $this->getLayout());
    }
}
