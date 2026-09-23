<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Evals\EvalCase;
use FitOut\Extraction\GroundingValidator;
use FitOut\Ingestion\Local\LocalDocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroundingValidatorTest extends TestCase
{
    private const DOCUMENT = <<<'DOC'
    K10/120  Metal stud partition, 2 layers 15mm plasterboard each side,   1,250 m²
    M50/010  Carpet tiles 500x500 to open plan office — 842.5 sqm
    DOC;

    #[Test]
    public function a_verbatim_quote_with_a_stated_quantity_is_grounded(): void
    {
        $problems = (new GroundingValidator)->problems($this->item([
            'quantity' => 1250,
            'source_text' => 'Metal stud partition, 2 layers 15mm plasterboard each side, 1,250 m2',
        ]), self::DOCUMENT);

        $this->assertSame([], $problems, 'whitespace, case and m² vs m2 are not differences');
    }

    #[Test]
    public function a_paraphrased_source_is_rejected(): void
    {
        $problems = (new GroundingValidator)->problems($this->item([
            'source_text' => 'Metal stud partitions, double boarded, 1,250 m2',
            'quantity' => 1250,
        ]), self::DOCUMENT);

        $this->assertContains('source_text is not a verbatim quote from the document.', $problems);
    }

    #[Test]
    public function a_quantity_that_is_not_written_in_the_quote_is_rejected(): void
    {
        // 842.5 is in the line; 850 is a model "rounding up".
        $problems = (new GroundingValidator)->problems($this->item([
            'trade' => 'flooring',
            'quantity' => 850,
            'source_text' => 'Carpet tiles 500x500 to open plan office — 842.5 sqm',
        ]), self::DOCUMENT);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('does not appear in source_text', $problems[0]);
    }

    /** @return iterable<string, array{string, float, string, string, string}> */
    public static function numbersThatAreNotTheQuantity(): iterable
    {
        yield 'a per-floor rate' => ['Carpet tiles to open plan, 3 floors at 420 m2 per floor', 420, 'm2', 'is a rate', 'flooring'];
        yield 'the multiplier of a rate' => ['Carpet tiles to open plan, 3 floors at 420 m2 per floor', 3, 'm2', 'not the number source_text states with a unit', 'flooring'];
        yield 'one side of a dimension' => ['Entrance matting, recessed, 3.0 x 2.5 m', 2.5, 'm', 'is a dimension', 'flooring'];
        yield 'the other side' => ['Entrance matting, recessed, 3.0 x 2.5 m', 3, 'm', 'is a dimension', 'flooring'];
        yield 'a tile size' => ['Suspended ceiling, 600x600 tile, levels 1-3    1,260 m2', 600, 'm2', 'is a dimension', 'ceilings'];
        yield 'a range' => ['Suspended ceiling, 600x600 tile, levels 1-3    1,260 m2', 3, 'm2', 'part of a reference, range or price', 'ceilings'];
        yield 'a reference number' => ['PS-1  Provisional sum for out-of-hours working    £5,000', 1, 'item', 'part of a reference, range or price', 'joinery'];
        yield 'money' => ['PS-1  Provisional sum for out-of-hours working    £5,000', 5000, 'item', 'part of a reference, range or price', 'joinery'];
    }

    #[Test]
    #[DataProvider('numbersThatAreNotTheQuantity')]
    public function a_number_in_the_quote_that_is_not_its_quantity_is_rejected(string $source, float $quantity, string $unit, string $reason, string $trade): void
    {
        $problems = (new GroundingValidator)->problems($this->item([
            'trade' => $trade, 'quantity' => $quantity, 'unit' => $unit, 'source_text' => $source,
        ]), $source);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString($reason, $problems[0]);
    }

    #[Test]
    public function the_unit_must_match_the_one_written_next_to_the_quantity(): void
    {
        $source = 'K40/020   MF plasterboard bulkhead to perimeter    96      m';

        $problems = (new GroundingValidator)->problems($this->item(['quantity' => 96, 'unit' => 'm2', 'source_text' => $source]), $source);

        $this->assertSame(['unit m2 does not match source_text, which gives 96 m.'], $problems);
    }

    #[Test]
    public function a_count_written_without_a_unit_is_still_grounded(): void
    {
        $source = '- new data floor boxes x 22';

        $problems = (new GroundingValidator)->problems($this->item(['trade' => 'electrical', 'quantity' => 22, 'unit' => 'nr', 'source_text' => $source]), $source);

        $this->assertSame([], $problems);
    }

    #[Test]
    public function a_trade_its_own_quote_contradicts_is_rejected(): void
    {
        $source = 'M50/010  Carpet tiles 500x500, loop pile    690 m2';

        $problems = (new GroundingValidator)->problems($this->item(['trade' => 'ceilings', 'quantity' => 690, 'source_text' => $source]), $source);

        $this->assertSame(['trade ceilings is not supported by source_text, which reads as flooring ("carpet"); correct the trade or explain it in warnings.'], $problems);
    }

    /** @return iterable<string, array{string, string}> */
    public static function tradesTheQuoteDoesNotContradict(): iterable
    {
        yield 'several trades, including its own' => ['doors', 'Door and frame, including decoration    12 nr'];
        yield 'no trade words at all' => ['joinery', 'Item 7 as drawing J-204    1 item'];
        yield 'the word of another trade inside its own' => ['electrical', '118 nr LED recessed panels 600x600'];
        yield 'a location, not a trade' => ['flooring', 'F-02  Type B to WCs and tea point   44 nr'];
    }

    #[Test]
    #[DataProvider('tradesTheQuoteDoesNotContradict')]
    public function a_trade_is_only_rejected_when_the_quote_contradicts_it(string $trade, string $source): void
    {
        $quantity = (float) (preg_match('/(\d+)\s+(?:nr|item)/', $source, $m) === 1 ? $m[1] : 0);
        $unit = str_contains($source, 'item') ? 'item' : 'nr';

        $problems = (new GroundingValidator)->problems($this->item(['trade' => $trade, 'quantity' => $quantity, 'unit' => $unit, 'source_text' => $source]), $source);

        $this->assertSame([], $problems);
    }

    /**
     * Counter-examples from review: each once passed or failed the wrong way.
     *
     * @return iterable<string, array{string, string, float, string, ?string}>
     */
    public static function reviewCounterExamples(): iterable
    {
        yield 'a height is not a quantity' => ['Metal stud partition, 3.2 m high, 45 m', 'partitions', 3.2, 'm', 'is a dimension'];
        yield 'the length next to a height is' => ['Metal stud partition, 3.2 m high, 45 m', 'partitions', 45, 'm', null];
        yield '"lightweight" is not lighting' => ['Lightweight partition 60 m2', 'electrical', 60, 'm2', 'reads as partitions'];
        yield '"studio" is not stud' => ['Studio lighting track 20 m', 'partitions', 20, 'm', 'reads as electrical'];
        yield 'WC cubicles are joinery' => ['WC cubicles, 6 nr', 'joinery', 6, 'nr', null];
    }

    #[Test]
    #[DataProvider('reviewCounterExamples')]
    public function review_counter_examples(string $source, string $trade, float $quantity, string $unit, ?string $reason): void
    {
        $problems = (new GroundingValidator)->problems($this->item(['trade' => $trade, 'quantity' => $quantity, 'unit' => $unit, 'source_text' => $source]), $source);

        if ($reason === null) {
            $this->assertSame([], $problems);
        } else {
            $this->assertCount(1, $problems);
            $this->assertStringContainsString($reason, $problems[0]);
        }
    }

    #[Test]
    public function a_spec_reference_must_be_written_by_the_quoted_line(): void
    {
        $validator = new GroundingValidator;
        $quote = 'Metal stud partition, 2 layers 15mm plasterboard each side,   1,250 m²';

        $this->assertSame([], $validator->problems($this->item(['quantity' => 1250, 'source_text' => $quote, 'spec_reference' => 'K10/120']), self::DOCUMENT), 'the reference in the column before the quote');
        $this->assertSame([], $validator->problems($this->item(['quantity' => 1250, 'source_text' => $quote, 'spec_reference' => null]), self::DOCUMENT));
        $this->assertStringContainsString('spec_reference is not written', $validator->problems($this->item(['quantity' => 1250, 'source_text' => $quote, 'spec_reference' => 'K10/999']), self::DOCUMENT)[0], 'invented');
        $this->assertStringContainsString('spec_reference is not written', $validator->problems($this->item(['quantity' => 1250, 'source_text' => $quote, 'spec_reference' => 'M50/010']), self::DOCUMENT)[0], 'another line\'s reference');
    }

    #[Test]
    public function a_description_cannot_carry_numbers_the_document_does_not_have(): void
    {
        $quote = 'Carpet tiles 500x500 to open plan office — 842.5 sqm';
        $item = ['trade' => 'flooring', 'quantity' => 842.5, 'spec_reference' => 'M50/010', 'source_text' => $quote];

        $this->assertSame([], (new GroundingValidator)->problems($this->item(['description' => 'Carpet tiles, 500x500'] + $item), self::DOCUMENT));
        $this->assertSame(
            ['description has numbers the document does not (600); describe only what the line says.'],
            (new GroundingValidator)->problems($this->item(['description' => 'Carpet tiles, 600x600'] + $item), self::DOCUMENT),
        );
    }

    /**
     * The checks must never reject what the golden cases say is correct: every
     * expected item must have a faithful quote that passes — the line it sits
     * on, or that line with the one before when the item wraps (as the tea
     * point in 02 does, where "worktop" and "1 item" are on different lines).
     */
    #[Test]
    public function every_expected_item_in_the_golden_cases_is_grounded(): void
    {
        foreach (EvalCase::loadDirectory(__DIR__.'/../../../evals/cases', new LocalDocumentReader) as $case) {
            foreach ($case->expected as $expected) {
                $lines = explode("\n", $case->document);
                $at = array_find_key($lines, static fn (string $l): bool => str_contains(mb_strtolower($l), mb_strtolower($expected->sourceContains)));
                $this->assertIsInt($at, "{$case->name}: no line contains \"{$expected->sourceContains}\"");

                $quotes = [trim($lines[$at]), ...($at > 0 ? [trim($lines[$at - 1]).' '.trim($lines[$at])] : [])];
                $problems = array_map(static fn (string $quote): array => (new GroundingValidator)->problems([
                    'trade' => $expected->trade->value,
                    'description' => $expected->sourceContains,
                    'quantity' => $expected->quantity->value,
                    'unit' => $expected->unit->value,
                    'spec_reference' => null,
                    'source_text' => $quote,
                ], $case->document), $quotes);

                $this->assertContains([], $problems, "{$case->name}: {$expected->describe()} — ".json_encode($problems));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides): array
    {
        return $overrides + ['trade' => 'partitions', 'description' => 'Metal stud partition', 'unit' => 'm2', 'spec_reference' => null];
    }
}
