<?php

declare(strict_types=1);

namespace FitOut\Llm\Anthropic;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaUsage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\Exceptions\LlmRefused;
use FitOut\Llm\Exceptions\LlmUnavailable;
use FitOut\Llm\LlmClient;
use FitOut\Llm\StructuredRequest;
use FitOut\Llm\StructuredResponse;
use FitOut\Llm\Usage;
use JsonException;

/**
 * Claude via the official SDK, using structured outputs: the API constrains
 * decoding to the JSON schema, so "the model returned broken JSON" is not a
 * failure mode this adapter has to handle.
 *
 * Non-retryable API errors (400, 401, 404…) propagate as SDK exceptions on
 * purpose — they are bugs or misconfiguration, and retrying would hide them.
 */
final readonly class AnthropicLlmClient implements LlmClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  'low'|'medium'|'high'|'xhigh'|'max'  $effort
     * @param  bool  $refusalFallback  server-side fallback on refusal — a beta feature, so it can be
     *                                 turned off (RFQ_LLM_REFUSAL_FALLBACK=false) and the request then
     *                                 carries no beta header at all; a refusal fails the piece instead
     */
    public function __construct(
        private Client $client,
        private string $model,
        private string $effort = 'medium',
        private bool $refusalFallback = true,
    ) {}

    public function structured(StructuredRequest $request): StructuredResponse
    {
        $startedAt = hrtime(true);

        try {
            $outputConfig = ['effort' => $this->effort, 'format' => ['type' => 'json_schema', 'schema' => $request->schema]];
            $message = $this->refusalFallback
                // If the model declines on policy grounds, let the API retry on its
                // configured fallback inside the same call instead of failing the job.
                ? $this->client->beta->messages->create(
                    maxTokens: $request->maxTokens,
                    messages: $request->messages,
                    model: $this->model,
                    system: $request->system,
                    thinking: ['type' => 'adaptive'],
                    outputConfig: $outputConfig,
                    fallbacks: 'default',
                    betas: [self::FALLBACK_BETA],
                )
                : $this->client->beta->messages->create(
                    maxTokens: $request->maxTokens,
                    messages: $request->messages,
                    model: $this->model,
                    system: $request->system,
                    thinking: ['type' => 'adaptive'],
                    outputConfig: $outputConfig,
                );
        } catch (RateLimitException|InternalServerException|APIConnectionException $e) {
            throw new LlmUnavailable($e->getMessage(), previous: $e);
        }

        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        if ($message->stopReason === 'refusal') {
            throw new LlmRefused(sprintf(
                'Model declined the request (%s).',
                $message->stopDetails->category ?? 'no category',
            ));
        }
        if ($message->stopReason === 'max_tokens') {
            throw new LlmOutputTruncated("Response exceeded {$request->maxTokens} tokens.", self::usage($message->usage), $message->model);
        }

        $json = '';
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $json .= $block->text;
            }
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Structured outputs make this unreachable in practice; if it happens, it is an upstream bug.
            throw new LlmUnavailable('Structured output was not valid JSON: '.$e->getMessage(), previous: $e);
        }
        if (! is_array($data)) {
            throw new LlmUnavailable('Structured output was not a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return new StructuredResponse(
            data: $data,
            rawJson: $json,
            model: $message->model,
            usage: self::usage($message->usage),
            durationMs: $durationMs,
        );
    }

    private static function usage(BetaUsage $usage): Usage
    {
        return new Usage(
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadTokens: $usage->cacheReadInputTokens ?? 0,
            cacheWriteTokens: $usage->cacheCreationInputTokens ?? 0,
        );
    }
}
