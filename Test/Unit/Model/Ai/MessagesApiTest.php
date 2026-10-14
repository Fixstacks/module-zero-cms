<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Test\Unit\Model\Ai;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Zero\Cms\Model\Ai\MessagesApi;

class MessagesApiTest extends TestCase
{
    private function config(array $values): ScopeConfigInterface
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn ($path) => $values[$path] ?? null);

        return $config;
    }

    public function testBedrockWithTheServersOwnCredentials(): void
    {
        $calls = [];
        $client = new class ($calls) extends BedrockRuntimeClient {
            public function __construct(private array &$calls)
            {
            }

            public function invokeModel(array $args = [])
            {
                $this->calls[] = $args;

                return ['body' => '{"content":[{"type":"text","text":"hi"}],"stop_reason":"end_turn"}'];
            }
        };
        $args = null;
        $api = new MessagesApi(
            $this->createStub(CurlFactory::class),
            $this->config(['zero_cms/ai/provider' => 'bedrock', 'zero_cms/ai/bedrock_region' => 'eu-central-1']),
            $this->createStub(EncryptorInterface::class),
            new NullLogger(),
            180,
            function (array $clientArgs) use ($client, &$args) {
                $args = $clientArgs;

                return $client;
            }
        );

        $this->assertTrue($api->isConfigured());
        $response = $api->send(['max_tokens' => 10, 'messages' => []], 'eu.anthropic.claude-haiku-4-5-20251001-v1:0');

        $this->assertSame('hi', $response['content'][0]['text']);
        $this->assertSame('eu-central-1', $args['region']);
        $this->assertSame('eu.anthropic.claude-haiku-4-5-20251001-v1:0', $calls[0]['modelId']);
        $body = json_decode($calls[0]['body'], true);
        $this->assertSame('bedrock-2023-05-31', $body['anthropic_version']);
        $this->assertArrayNotHasKey('model', $body);
    }

    public function testBedrockWithAnApiKey(): void
    {
        $sent = [];
        $curl = $this->createStub(Curl::class);
        $curl->method('addHeader')->willReturnCallback(function ($name, $value) use (&$sent) {
            $sent['headers'][$name] = $value;
        });
        $curl->method('post')->willReturnCallback(function ($url, $body) use (&$sent) {
            $sent['url'] = $url;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"content":[]}');
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $api = new MessagesApi(
            $factory,
            $this->config(['zero_cms/ai/provider' => 'bedrock', 'zero_cms/ai/bedrock_api_key' => 'bedrock-key']),
            $this->createStub(EncryptorInterface::class),
            new NullLogger()
        );

        $api->send(['messages' => []], 'us.anthropic.claude-haiku-4-5-20251001-v1:0');

        $this->assertSame('https://bedrock-runtime.us-east-1.amazonaws.com/model/us.anthropic.claude-haiku-4-5-20251001-v1%3A0/invoke', $sent['url']);
        $this->assertSame('Bearer bedrock-key', $sent['headers']['Authorization']);
    }

    public function testAnthropicNeedsItsKey(): void
    {
        $api = new MessagesApi(
            $this->createStub(CurlFactory::class),
            $this->config([]),
            $this->createStub(EncryptorInterface::class),
            new NullLogger()
        );

        $this->assertSame('anthropic', $api->provider());
        $this->assertFalse($api->isConfigured());
    }
}
