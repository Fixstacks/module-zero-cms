<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Ai;

/**
 * New text for one field: `action` write|improve|shorten, the field's
 * `text`, and `context` (JSON: component, field, max, multiline, siblings).
 */
class Assist extends AbstractAi
{
    /**
     * @inheritDoc
     */
    protected function answer(array $target): array
    {
        $context = json_decode((string) $this->getRequest()->getParam('context', ''), true);
        $context = (is_array($context) ? $context : []) + ['page' => $target['title']];

        return ['text' => $this->assistant->assist(
            (string) $this->getRequest()->getParam('action', ''),
            (string) $this->getRequest()->getParam('text', ''),
            $context,
            $target['store']
        )];
    }
}
