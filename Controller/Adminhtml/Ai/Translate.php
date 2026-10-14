<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Ai;

/**
 * The posted `content`, translated into `locale` (e.g. de_DE), or into the
 * store view's language when none is given.
 */
class Translate extends AbstractAi
{
    /**
     * @inheritDoc
     */
    protected function answer(array $target): array
    {
        $content = $this->postedContent();

        return ['content' => $this->assistant->translate(
            is_array($content) ? $content : [],
            $target['store'],
            (string) $this->getRequest()->getParam('locale', '')
        )];
    }
}
