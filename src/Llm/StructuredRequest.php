<?php

declare(strict_types=1);

namespace FitOut\Llm;

final readonly class StructuredRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON Schema the response must satisfy
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $schema,
        public int $maxTokens = 16000,
    ) {}

    public function withFollowUp(string $assistant, string $user): self
    {
        return new self(
            $this->system,
            [...$this->messages, ['role' => 'assistant', 'content' => $assistant], ['role' => 'user', 'content' => $user]],
            $this->schema,
            $this->maxTokens,
        );
    }
}
