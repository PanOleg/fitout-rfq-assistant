<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\LineItem;
use FitOut\Llm\LlmClient;
use FitOut\Llm\StructuredRequest;
use FitOut\Llm\Usage;

/**
 * Extract → validate → ask the model to repair what failed → validate again.
 *
 * The loop is bounded: after $maxRepairs rounds, whatever still fails is
 * returned as rejected rather than silently dropped or silently kept, so a
 * human reviewing the tender can see exactly what the tool was unsure of.
 */
final readonly class LlmLineItemExtractor implements LineItemExtractor
{
    public function __construct(
        private LlmClient $llm,
        private GroundingValidator $validator = new GroundingValidator,
        private int $maxRepairs = 2,
    ) {}

    public function extract(string $document): ExtractionResult
    {
        $request = new StructuredRequest(
            system: ExtractionPrompt::system(),
            messages: [['role' => 'user', 'content' => ExtractionPrompt::userMessage($document)]],
            schema: ExtractionPrompt::schema(),
        );

        $usage = new Usage;
        $durationMs = 0;
        $attempt = 0;

        while (true) {
            $attempt++;
            $response = $this->llm->structured($request);
            $usage = $usage->plus($response->usage);
            $durationMs += $response->durationMs;

            [$items, $violations] = $this->validate($response->data, $document);

            if ($violations === [] || $attempt > $this->maxRepairs) {
                /** @var list<string> $warnings */
                $warnings = $response->data['warnings'] ?? [];

                return new ExtractionResult(
                    items: $items,
                    warnings: $warnings,
                    rejected: $violations,
                    model: $response->model,
                    promptVersion: ExtractionPrompt::VERSION,
                    usage: $usage,
                    attempts: $attempt,
                    durationMs: $durationMs,
                );
            }

            $request = $request->withFollowUp($response->rawJson, ExtractionPrompt::repairMessage($violations));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{list<LineItem>, list<Violation>}
     */
    private function validate(array $data, string $document): array
    {
        $items = [];
        $violations = [];

        $raw = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        foreach ($raw as $index => $item) {
            if (! is_array($item)) {
                $violations[] = new Violation($index, [], ['item is not an object.']);

                continue;
            }

            /** @var array<string, mixed> $item */
            $problems = $this->validator->problems($item, $document);
            if ($problems === []) {
                /** @var array{trade: string, description: string, quantity: float|int, unit: string, spec_reference: ?string, source_text: string} $item */
                $items[] = LineItem::fromArray($item);
            } else {
                $violations[] = new Violation($index, $item, $problems);
            }
        }

        return [$items, $violations];
    }
}
