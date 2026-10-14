<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Ai;

use Magento\Framework\Exception\LocalizedException;

/**
 * A language model for the Zero CMS editor, called from the server only
 * (the key never reaches the browser). Add another provider: implement this
 * and register it in di.xml (Zero\Cms\Model\Ai\Assistant, `clients`).
 */
interface ClientInterface
{
    /**
     * Plain text answer.
     *
     * @throws LocalizedException When the provider can't be reached or refuses
     */
    public function text(string $system, string $prompt, int $maxTokens): string;

    /**
     * Structured answer: the model must call the given tool; returns its input.
     *
     * @param array $inputSchema JSON Schema of the tool input
     * @throws LocalizedException
     */
    public function structured(string $system, string $prompt, string $toolName, string $toolDescription, array $inputSchema, int $maxTokens): array;
}
