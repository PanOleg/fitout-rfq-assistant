<?php

declare(strict_types=1);

namespace FitOut\Llm;

final readonly class StructuredResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public string $rawJson,
        public string $model,
        public Usage $usage,
        public int $durationMs,
    ) {}
}
