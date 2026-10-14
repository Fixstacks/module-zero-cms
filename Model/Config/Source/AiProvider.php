<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * AI providers for Stores > Configuration > Zero > CMS (di.xml `providers`).
 */
class AiProvider implements OptionSourceInterface
{
    /**
     * @param array<string, string> $providers
     */
    public function __construct(
        private readonly array $providers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->providers as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }

        return $options;
    }
}
