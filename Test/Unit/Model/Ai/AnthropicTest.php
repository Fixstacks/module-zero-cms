<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Test\Unit\Model\Ai;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Zero\Cms\Model\Ai\Anthropic;
use Zero\Cms\Model\Ai\MessagesApi;

class AnthropicTest extends TestCase
{
    private Curl&MockObject $curl;
    private array $sent = [];
    private array $headers = [];

    private function client(string $key = 'sk-test', string $model = ''): Anthropic
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('addHeader')->willReturnCallback(function ($name, $value) {
            $this->headers[$name] = $value;
        });
        $this->curl->expects($this->atMost(1))->method('post')->willReturnCallback(function ($url, $body) {
            $this->sent = ['url' => $url, 'body' => json_decode($body, true)];
        });
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($this->curl);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            [Anthropic::XML_PATH_KEY, 'default', null, '0:3:encrypted'],
            [Anthropic::XML_PATH_MODEL, 'default', null, $model],
        ]);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn($key);

        return new Anthropic(new MessagesApi($factory, $config, $encryptor, new NullLogger()), $config);
    }

    private function respond(int $status, array $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn(json_encode($body));
    }

    public function testStructuredForcesTheToolAndReturnsItsInput(): void
    {
        $client = $this->client();
        $this->respond(200, ['content' => [
            ['type' => 'text', 'text' => 'Here you go'],
            ['type' => 'tool_use', 'name' => 'page', 'input' => ['components' => [['type' => 'hero']]]],
        ], 'stop_reason' => 'tool_use']);

        $input = $client->structured('sys', 'make a page', 'page', 'desc', ['type' => 'object'], 1000);

        $this->assertSame(['components' => [['type' => 'hero']]], $input);
        $this->assertSame('https://api.anthropic.com/v1/messages', $this->sent['url']);
        $this->assertSame(Anthropic::DEFAULT_MODEL, $this->sent['body']['model']);
        $this->assertSame(['type' => 'tool', 'name' => 'page'], $this->sent['body']['tool_choice']);
        $this->assertSame('sys', $this->sent['body']['system']);
        $this->assertSame('sk-test', $this->headers['x-api-key']);
        $this->assertSame('2023-06-01', $this->headers['anthropic-version']);
    }

    public function testTextJoinsTextBlocksAndUsesTheConfiguredModel(): void
    {
        $client = $this->client('sk-test', 'claude-haiku-4-5-20251001');
        $this->respond(200, ['content' => [['type' => 'text', 'text' => ' Hello '], ['type' => 'text', 'text' => 'world ']]]);

        $this->assertSame('Hello world', $client->text('sys', 'hi', 100));
        $this->assertSame('claude-haiku-4-5-20251001', $this->sent['body']['model']);
        $this->assertArrayNotHasKey('tools', $this->sent['body']);
    }

    public function testApiErrorsBecomeReadableMessages(): void
    {
        $client = $this->client();
        $this->respond(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('invalid x-api-key');
        $client->text('sys', 'hi', 100);
    }

    public function testCutOffAnswerIsExplained(): void
    {
        $client = $this->client();
        $this->respond(200, ['content' => [], 'stop_reason' => 'max_tokens']);

        $this->expectExceptionMessage('cut off');
        $client->structured('sys', 'p', 'page', 'd', ['type' => 'object'], 10);
    }

    public function testNoKeyNoRequest(): void
    {
        $client = $this->client('');
        $this->curl->expects($this->never())->method('post');

        $this->expectExceptionMessage('API key');
        $client->text('sys', 'hi', 100);
    }
}
