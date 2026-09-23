<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use FitOut\Domain\LineItem;
use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\LlmClient;
use FitOut\Llm\StructuredRequest;
use FitOut\Llm\Usage;

/**
 * Extract → validate → ask the model to repair what failed → validate again.
 *
 * The loop is bounded: after $maxRepairs rounds, whatever still fails is
 * returned as rejected rather than silently dropped or silently kept, so a
 * human reviewing the tender can see exactly what the tool was unsure of.
 * Nothing accepted in an earlier round is lost in a later one.
 */
final readonly class LlmLineItemExtractor implements LineItemExtractor
{
    public function __construct(
        private LlmClient $llm,
        private GroundingValidator $validator = new GroundingValidator,
        private int $maxRepairs = 2,
    ) {}

    /** Quotes are validated against $document alone, so an item taken from $context is rejected. */
    public function extract(string $document, string $context = ''): ExtractionResult
    {
        $request = new StructuredRequest(
            system: ExtractionPrompt::system(),
            messages: [['role' => 'user', 'content' => ExtractionPrompt::userMessage($document, $context)]],
            schema: ExtractionPrompt::schema(),
        );

        $usage = new Usage;
        $durationMs = 0;
        $attempt = 0;
        /** @var array<string, LineItem> $accepted by quote, across rounds */
        $accepted = [];
        /** @var list<string> $warnings */
        $warnings = [];

        while (true) {
            $attempt++;
            try {
                $response = $this->llm->structured($request);
            } catch (LlmOutputTruncated $e) {
                throw $e->plus($usage); // earlier rounds were paid for too
            }
            $usage = $usage->plus($response->usage);
            $durationMs += $response->durationMs;

            [$items, $violations] = $this->validate($response->data, $document);

            // The repair turn asks for the complete list again, and a model does not always
            // comply. An item that passed in an earlier round and is missing now is kept, not
            // dropped silently; one it restates (same quote) is replaced by the new version.
            foreach ($items as $item) {
                $accepted[self::key($item->sourceText)] = $item;
            }
            /** @var list<string> $roundWarnings */
            $roundWarnings = is_array($response->data['warnings'] ?? null) ? $response->data['warnings'] : [];
            $warnings = array_values(array_unique([...$warnings, ...$roundWarnings]));

            if ($violations === [] || $attempt > $this->maxRepairs) {
                return new ExtractionResult(
                    items: array_values($accepted),
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

    private static function key(string $sourceText): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($sourceText)));
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
