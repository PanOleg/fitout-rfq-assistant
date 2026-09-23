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
        /** @var list<LineItem> $accepted across rounds */
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

            $accepted = self::carryForward($accepted, $items);
            /** @var list<string> $roundWarnings */
            $roundWarnings = is_array($response->data['warnings'] ?? null) ? $response->data['warnings'] : [];
            $warnings = array_values(array_unique([...$warnings, ...$roundWarnings]));

            if ($violations === [] || $attempt > $this->maxRepairs) {
                return new ExtractionResult(
                    items: $accepted,
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
     * The repair turn asks for the complete list again, and a model does not
     * always comply. Every item of the new round is kept as it is — two items
     * may share one quote ("4 nr pans and 4 nr basins"). Each new item then
     * stands in for one earlier item with the same quote, quantity and unit,
     * the one with the same description first; earlier items nothing stood in
     * for were left out by the model and are carried forward, not dropped.
     *
     * @param  list<LineItem>  $earlier
     * @param  list<LineItem>  $round
     * @return list<LineItem>
     */
    private static function carryForward(array $earlier, array $round): array
    {
        $open = $earlier;
        foreach ($round as $item) {
            $same = array_keys(array_filter($open, static fn (LineItem $e): bool => self::identity($e) === self::identity($item)));
            if ($same === []) {
                continue;
            }
            $exact = array_find($same, static fn (int $k): bool => $open[$k]->description === $item->description);
            unset($open[$exact ?? $same[0]]);
        }

        return [...$round, ...array_values($open)];
    }

    private static function identity(LineItem $item): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($item->sourceText)))."\0{$item->quantity->value}\0{$item->unit->value}";
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
