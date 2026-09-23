<?php

declare(strict_types=1);

namespace Tests\Unit\Llm;

use Anthropic\Client;
use FitOut\Llm\Anthropic\AnthropicLlmClient;
use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\Exceptions\LlmRefused;
use FitOut\Llm\Exceptions\LlmUnavailable;
use FitOut\Llm\StructuredRequest;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Exercises the adapter against canned HTTP responses — no network, no API key. */
final class AnthropicLlmClientTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    #[Test]
    public function it_requests_schema_constrained_output_and_maps_usage(): void
    {
        $llm = $this->clientReturning(new Response(200, ['content-type' => 'application/json'], $this->message('{"items":[]}', 'end_turn')));

        $response = $llm->structured($this->request());

        $this->assertSame(['items' => []], $response->data);
        $this->assertSame(1200, $response->usage->inputTokens);
        $this->assertSame(80, $response->usage->outputTokens);

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('claude-opus-5', $body['model']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $this->sent[0]['request']->getHeaderLine('anthropic-beta'));
    }

    #[Test]
    public function a_refusal_is_reported_not_parsed(): void
    {
        $llm = $this->clientReturning(new Response(200, ['content-type' => 'application/json'], $this->message('', 'refusal')));

        $this->expectException(LlmRefused::class);
        $llm->structured($this->request());
    }

    #[Test]
    public function hitting_max_tokens_is_reported_as_truncation(): void
    {
        $llm = $this->clientReturning(new Response(200, ['content-type' => 'application/json'], $this->message('{"items":[', 'max_tokens')));

        try {
            $llm->structured($this->request());
            $this->fail('Expected truncation.');
        } catch (LlmOutputTruncated $e) {
            $this->assertGreaterThan(0, $e->usage->outputTokens, 'a truncated answer was still billed');
        }
    }

    #[Test]
    public function the_refusal_fallback_beta_can_be_turned_off(): void
    {
        $this->clientReturning(new Response(200, ['content-type' => 'application/json'], $this->message('{"items":[],"warnings":[]}', 'end_turn')))->structured($this->request());
        $this->clientReturning(new Response(200, ['content-type' => 'application/json'], $this->message('{"items":[],"warnings":[]}', 'end_turn')), refusalFallback: false)->structured($this->request());

        [$with, $without] = array_map(static fn (array $t) => $t['request'], $this->sent);
        $this->assertStringContainsString('server-side-fallback', $with->getHeaderLine('anthropic-beta'));
        $this->assertArrayHasKey('fallbacks', json_decode((string) $with->getBody(), true));
        $this->assertSame('', $without->getHeaderLine('anthropic-beta'), 'no beta header at all');
        $this->assertArrayNotHasKey('fallbacks', json_decode((string) $without->getBody(), true));
    }

    #[Test]
    public function rate_limits_and_overload_become_retryable(): void
    {
        $llm = $this->clientReturning(new Response(429, ['content-type' => 'application/json'], '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'));

        $this->expectException(LlmUnavailable::class);
        $llm->structured($this->request());
    }

    private function request(): StructuredRequest
    {
        return new StructuredRequest(
            system: 'Extract line items.',
            messages: [['role' => 'user', 'content' => 'Supply and fix carpet tiles, 120 m2']],
            schema: ['type' => 'object', 'properties' => ['items' => ['type' => 'array']], 'required' => ['items'], 'additionalProperties' => false],
        );
    }

    private function clientReturning(Response $response, bool $refusalFallback = true): AnthropicLlmClient
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->sent));

        $sdk = new Client(apiKey: 'test-key', requestOptions: ['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]);

        return new AnthropicLlmClient($sdk, 'claude-opus-5', 'medium', $refusalFallback);
    }

    private function message(string $text, string $stopReason): string
    {
        return (string) json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 80],
        ]);
    }
}
