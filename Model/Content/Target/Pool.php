<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Content\Target;

use Magento\Framework\Exception\LocalizedException;

/**
 * The editable types by code (di.xml `types`): cms_page, cms_block, and what
 * other modules add.
 */
class Pool
{
    /**
     * @param array<string, TypeInterface> $types
     */
    public function __construct(private readonly array $types = [])
    {
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $code): TypeInterface
    {
        if (!isset($this->types[$code])) {
            throw new LocalizedException(__('Unknown content type.'));
        }

        return $this->types[$code];
    }
}
