<?php

declare(strict_types=1);

namespace Tests\Support;

use FitOut\Llm\LlmClient;
use FitOut\Llm\StructuredRequest;
use FitOut\Llm\StructuredResponse;
use FitOut\Llm\Usage;
use RuntimeException;

/** Replays queued answers in order and records every request it received. */
final class ScriptedLlmClient implements LlmClient
{
    /** @var list<StructuredRequest> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $answers;

    /** @param array<string, mixed> ...$answers */
    public function __construct(array ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->requests[] = $request;
        $answer = array_shift($this->answers) ?? throw new RuntimeException('ScriptedLlmClient ran out of answers.');

        return new StructuredResponse($answer, (string) json_encode($answer), 'claude-opus-5', new Usage(1000, 200), 5);
    }
}
