<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Ai;

/**
 * A page from a description (`brief`), in the store view's language.
 */
class Generate extends AbstractAi
{
    /**
     * @inheritDoc
     */
    protected function answer(array $target): array
    {
        return ['content' => $this->assistant->generate(
            (string) $this->getRequest()->getParam('brief', ''),
            $target['store'],
            $target['title']
        )];
    }
}
