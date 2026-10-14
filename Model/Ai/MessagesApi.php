<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Model\Ai;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Claude's Messages API, from the provider in Stores > Configuration > Zero >
 * CMS > AI (used by Zero CMS and the AI Assistant): the Anthropic API, or
 * Amazon Bedrock InvokeModel with a Bedrock API key or the server's own AWS
 * role (same body, Bedrock's anthropic_version, model id in the URL).
 */
class MessagesApi
{
    public const XML_PATH_PROVIDER = 'zero_cms/ai/provider';
    public const XML_PATH_KEY = 'zero_cms/ai/api_key';
    public const XML_PATH_BEDROCK_REGION = 'zero_cms/ai/bedrock_region';
    public const XML_PATH_BEDROCK_KEY = 'zero_cms/ai/bedrock_api_key';
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const BEDROCK_VERSION = 'bedrock-2023-05-31';

    /** @var callable(array): BedrockRuntimeClient */
    private $bedrockFactory;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 180,
        ?callable $bedrockFactory = null
    ) {
        $this->bedrockFactory = $bedrockFactory ?? static fn (array $args) => new BedrockRuntimeClient($args);
    }

    public function provider(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_PROVIDER) === 'bedrock' ? 'bedrock' : 'anthropic';
    }

    /**
     * Ready to send: Bedrock needs nothing more (the server's AWS
     * credentials will do), Anthropic needs its key.
     */
    public function isConfigured(): bool
    {
        return $this->provider() === 'bedrock' || $this->secret(self::XML_PATH_KEY) !== '';
    }

    /**
     * One Messages API request (`model` is the Anthropic or the Bedrock id,
     * as the provider takes it); the response as an array.
     *
     * @param string|null $url Another Anthropic endpoint (a local stand-in for tests)
     * @throws LocalizedException
     */
    public function send(array $body, string $model, ?string $url = null): array
    {
        return $this->provider() === 'bedrock' ? $this->bedrock($body, $model) : $this->anthropic($body, $model, $url);
    }

    private function anthropic(array $body, string $model, ?string $url): array
    {
        $key = $this->secret(self::XML_PATH_KEY);
        if ($key === '') {
            throw new LocalizedException(__('Add an Anthropic API key in Stores > Configuration > Zero > CMS > AI.'));
        }
        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->timeout);
        $curl->addHeader('x-api-key', $key);
        $curl->addHeader('anthropic-version', self::ANTHROPIC_VERSION);
        $curl->addHeader('content-type', 'application/json');
        try {
            $curl->post($url ?: self::ANTHROPIC_URL, (string) json_encode(['model' => $model] + $body, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->logger->warning('Zero AI (Anthropic): ' . $e->getMessage());
            throw new LocalizedException(__('Claude could not be reached: %1', $e->getMessage()));
        }
        $response = (array) json_decode((string) $curl->getBody(), true);
        if ($curl->getStatus() !== 200) {
            $message = (string) ($response['error']['message'] ?? ('HTTP ' . $curl->getStatus()));
            $this->logger->warning('Zero AI (Anthropic): ' . $message);
            throw new LocalizedException(__('Claude refused the request: %1', $message));
        }

        return $response;
    }

    private function bedrock(array $body, string $model): array
    {
        $region = (string) ($this->scopeConfig->getValue(self::XML_PATH_BEDROCK_REGION) ?: 'us-east-1');
        $payload = (string) json_encode(['anthropic_version' => self::BEDROCK_VERSION] + $body, JSON_UNESCAPED_UNICODE);
        $key = $this->secret(self::XML_PATH_BEDROCK_KEY);
        try {
            if ($key !== '') {
                // A Bedrock API key: a bearer token for the runtime endpoint, no AWS signing.
                $curl = $this->curlFactory->create();
                $curl->setTimeout($this->timeout);
                $curl->addHeader('Authorization', 'Bearer ' . $key);
                $curl->addHeader('content-type', 'application/json');
                $curl->addHeader('accept', 'application/json');
                $curl->post(sprintf('https://bedrock-runtime.%s.amazonaws.com/model/%s/invoke', $region, rawurlencode($model)), $payload);
                $response = (array) json_decode((string) $curl->getBody(), true);
                if ($curl->getStatus() !== 200) {
                    throw new \RuntimeException((string) ($response['message'] ?? $response['Message'] ?? ('HTTP ' . $curl->getStatus())));
                }

                return $response;
            }
            $result = ($this->bedrockFactory)(['version' => 'latest', 'region' => $region, 'http' => ['timeout' => $this->timeout]])
                ->invokeModel(['modelId' => $model, 'contentType' => 'application/json', 'accept' => 'application/json', 'body' => $payload]);

            return (array) json_decode((string) $result['body'], true);
        } catch (\Throwable $e) {
            $message = $e instanceof \Aws\Exception\AwsException ? (string) ($e->getAwsErrorMessage() ?: $e->getMessage()) : $e->getMessage();
            $this->logger->warning('Zero AI (Bedrock): ' . $message);
            throw new LocalizedException(__('Amazon Bedrock refused the request: %1', $message));
        }
    }

    private function secret(string $path): string
    {
        $value = trim((string) $this->scopeConfig->getValue($path));

        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
