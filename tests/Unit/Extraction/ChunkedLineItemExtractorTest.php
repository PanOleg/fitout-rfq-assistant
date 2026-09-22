<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Extraction\ChunkedLineItemExtractor;
use FitOut\Extraction\ExtractionResult;
use FitOut\Extraction\LineItemExtractor;
use FitOut\Extraction\LlmLineItemExtractor;
use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\LlmClient;
use FitOut\Llm\StructuredRequest;
use FitOut\Llm\StructuredResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScriptedLlmClient;

final class ChunkedLineItemExtractorTest extends TestCase
{
    private const DOCUMENT = "Carpet tiles to open plan, 640 m2\n\nSuspended ceiling, 710 m2\n";

    #[Test]
    public function a_long_document_is_read_in_pieces_and_merged(): void
    {
        $llm = new ScriptedLlmClient(
            ['items' => [$this->item('flooring', 640, 'Carpet tiles to open plan, 640 m2')], 'warnings' => ['first']],
            ['items' => [$this->item('ceilings', 710, 'Suspended ceiling, 710 m2')], 'warnings' => ['second']],
        );

        $result = (new ChunkedLineItemExtractor(new LlmLineItemExtractor($llm), maxChars: 40))->extract(self::DOCUMENT);

        $this->assertCount(2, $llm->requests);
        $this->assertStringNotContainsString('Suspended', $llm->requests[0]->messages[0]['content']);
        $this->assertSame([640.0, 710.0], array_map(static fn ($i): float => $i->quantity->value, $result->items));
        $this->assertSame(['first', 'second'], $result->warnings);
        $this->assertSame(2000, $result->usage->inputTokens, 'usage is summed across pieces');
        $this->assertSame(2, $result->attempts);
    }

    #[Test]
    public function later_pieces_see_the_headings_above_them_but_cannot_quote_them(): void
    {
        $document = "FLOOR FINISHES\nF-01  Type A to open plan   585 m2\n\nF-02  Type B to WCs   44 m2\n";
        $llm = new ScriptedLlmClient(
            ['items' => [$this->item('flooring', 585, 'F-01  Type A to open plan   585 m2')], 'warnings' => []],
            // Second piece: one item from its own text, one lifted from the context.
            ['items' => [$this->item('flooring', 44, 'F-02  Type B to WCs   44 m2'), $this->item('flooring', 585, 'F-01  Type A to open plan   585 m2')], 'warnings' => []],
            ['items' => [$this->item('flooring', 44, 'F-02  Type B to WCs   44 m2')], 'warnings' => []],
        );

        $result = (new ChunkedLineItemExtractor(new LlmLineItemExtractor($llm), maxChars: 60))->extract($document);

        $this->assertStringNotContainsString('<context>', $llm->requests[0]->messages[0]['content']);
        $this->assertStringContainsString("<context>\nFLOOR FINISHES\n</context>", $llm->requests[1]->messages[0]['content']);
        $this->assertSame([585.0, 44.0], array_map(static fn ($i): float => $i->quantity->value, $result->items), 'F-01 is not counted twice');
        $this->assertStringContainsString('not a verbatim quote', $llm->requests[2]->messages[2]['content']);
    }

    #[Test]
    public function context_can_be_switched_off_to_measure_it(): void
    {
        $llm = new ScriptedLlmClient(['items' => [], 'warnings' => []], ['items' => [], 'warnings' => []]);

        (new ChunkedLineItemExtractor(new LlmLineItemExtractor($llm), maxChars: 25, withContext: false))->extract("HEADING\nLine one 1 nr\n\nLine two 2 nr\n");

        $this->assertStringNotContainsString('<context>', $llm->requests[1]->messages[0]['content']);
    }

    #[Test]
    public function a_piece_that_overflows_the_answer_is_halved_and_read_again(): void
    {
        $inner = new class implements LineItemExtractor
        {
            /** @var list<string> */
            public array $seen = [];

            public function extract(string $document, string $context = ''): ExtractionResult
            {
                $this->seen[] = $document;
                if (substr_count($document, 'm2') > 1) {
                    throw new LlmOutputTruncated('Response exceeded 16000 tokens.');
                }

                return (new LlmLineItemExtractor(new ScriptedLlmClient(['items' => [], 'warnings' => [trim($document)]])))->extract($document);
            }
        };

        $result = (new ChunkedLineItemExtractor($inner, maxChars: 1000, minChars: 10))->extract(self::DOCUMENT);

        $this->assertCount(3, $inner->seen, 'whole document, then each half');
        $this->assertSame(['Carpet tiles to open plan, 640 m2', 'Suspended ceiling, 710 m2'], $result->warnings);
    }

    #[Test]
    public function a_small_piece_that_still_overflows_fails(): void
    {
        $llm = new class implements LlmClient
        {
            public function structured(StructuredRequest $request): StructuredResponse
            {
                throw new LlmOutputTruncated('Response exceeded 16000 tokens.');
            }
        };

        $this->expectException(LlmOutputTruncated::class);

        (new ChunkedLineItemExtractor(new LlmLineItemExtractor($llm), maxChars: 1000, minChars: 1000))->extract(self::DOCUMENT);
    }

    /** @return array<string, mixed> */
    private function item(string $trade, int $quantity, string $source): array
    {
        return ['trade' => $trade, 'description' => $source, 'quantity' => $quantity, 'unit' => 'm2', 'spec_reference' => null, 'source_text' => $source];
    }
}
