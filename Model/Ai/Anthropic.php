<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Ai;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Claude through the Messages API, from Anthropic or Amazon Bedrock
 * (MessagesApi, by the configured provider). Structured answers use a forced
 * tool call, so the model returns JSON matching the schema.
 */
class Anthropic implements ClientInterface
{
    public const XML_PATH_KEY = 'zero_cms/ai/api_key';
    public const XML_PATH_MODEL = 'zero_cms/ai/model';
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    public const XML_PATH_BEDROCK_MODEL = 'zero_cms/ai/bedrock_model';
    public const DEFAULT_BEDROCK_MODEL = 'us.anthropic.claude-haiku-4-5-20251001-v1:0';

    public function __construct(
        private readonly MessagesApi $messagesApi,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function text(string $system, string $prompt, int $maxTokens): string
    {
        $response = $this->request([
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        $text = '';
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            }
        }

        return trim($text);
    }

    /**
     * @inheritDoc
     */
    public function structured(
        string $system,
        string $prompt,
        string $toolName,
        string $toolDescription,
        array $inputSchema,
        int $maxTokens
    ): array {
        $response = $this->request([
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'tools' => [['name' => $toolName, 'description' => $toolDescription, 'input_schema' => $inputSchema]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ]);
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $toolName) {
                return (array) ($block['input'] ?? []);
            }
        }
        if (($response['stop_reason'] ?? '') === 'max_tokens') {
            throw new LocalizedException(__('The answer was too long and got cut off. Ask for fewer components.'));
        }
        throw new LocalizedException(__('The model gave no usable answer. Please try again.'));
    }

    private function request(array $body): array
    {
        $model = $this->messagesApi->provider() === 'bedrock'
            ? (string) ($this->scopeConfig->getValue(self::XML_PATH_BEDROCK_MODEL) ?: self::DEFAULT_BEDROCK_MODEL)
            : (string) ($this->scopeConfig->getValue(self::XML_PATH_MODEL) ?: self::DEFAULT_MODEL);

        return $this->messagesApi->send($body, $model);
    }
}
