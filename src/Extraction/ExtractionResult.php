<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\LineItem;
use FitOut\Llm\ModelPricing;
use FitOut\Llm\Usage;

final readonly class ExtractionResult
{
    /**
     * @param  list<LineItem>  $items  items that passed every check
     * @param  list<string>  $warnings  ambiguities the model flagged instead of guessing
     * @param  list<Violation>  $rejected  items still failing checks after the last repair attempt
     */
    public function __construct(
        public array $items,
        public array $warnings,
        public array $rejected,
        public string $model,
        public string $promptVersion,
        public Usage $usage,
        public int $attempts,
        public int $durationMs,
    ) {}

    public function costUsd(): ?float
    {
        return ModelPricing::costUsd($this->model, $this->usage);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (LineItem $i): array => $i->toArray(), $this->items),
            'warnings' => $this->warnings,
            'rejected' => array_map(static fn (Violation $v): array => $v->toArray(), $this->rejected),
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'usage' => $this->usage->toArray(),
            'cost_usd' => $this->costUsd(),
            'attempts' => $this->attempts,
            'duration_ms' => $this->durationMs,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var list<array{trade: string, description: string, quantity: float|int, unit: string, spec_reference?: ?string, source_text: string}> $items */
        $items = $data['items'];
        /** @var list<array{index: int, item: array<string, mixed>, problems: non-empty-list<string>}> $rejected */
        $rejected = $data['rejected'];
        /** @var array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int} $usage */
        $usage = $data['usage'];
        /** @var list<string> $warnings */
        $warnings = $data['warnings'];

        return new self(
            items: array_map(LineItem::fromArray(...), $items),
            warnings: $warnings,
            rejected: array_map(static fn (array $r): Violation => new Violation($r['index'], $r['item'], $r['problems']), $rejected),
            model: (string) $data['model'],
            promptVersion: (string) $data['prompt_version'],
            usage: new Usage($usage['input_tokens'], $usage['output_tokens'], $usage['cache_read_tokens'], $usage['cache_write_tokens']),
            attempts: (int) $data['attempts'],
            durationMs: (int) $data['duration_ms'],
        );
    }
}
