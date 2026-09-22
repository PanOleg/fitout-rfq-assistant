<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Evals\EvalCase;
use FitOut\Extraction\GroundingValidator;
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

    /** @return iterable<string, array{string, float, string, string}> */
    public static function numbersThatAreNotTheQuantity(): iterable
    {
        yield 'a per-floor rate' => ['Carpet tiles to open plan, 3 floors at 420 m2 per floor', 420, 'm2', 'is a rate'];
        yield 'the multiplier of a rate' => ['Carpet tiles to open plan, 3 floors at 420 m2 per floor', 3, 'm2', 'not the number source_text states with a unit'];
        yield 'one side of a dimension' => ['Entrance matting, recessed, 3.0 x 2.5 m', 2.5, 'm', 'is a dimension'];
        yield 'the other side' => ['Entrance matting, recessed, 3.0 x 2.5 m', 3, 'm', 'is a dimension'];
        yield 'a tile size' => ['Suspended ceiling, 600x600 tile, levels 1-3    1,260 m2', 600, 'm2', 'is a dimension'];
        yield 'a range' => ['Suspended ceiling, 600x600 tile, levels 1-3    1,260 m2', 3, 'm2', 'part of a reference, range or price'];
        yield 'a reference number' => ['PS-1  Provisional sum for out-of-hours working    £5,000', 1, 'item', 'part of a reference, range or price'];
        yield 'money' => ['PS-1  Provisional sum for out-of-hours working    £5,000', 5000, 'item', 'part of a reference, range or price'];
    }

    #[Test]
    #[DataProvider('numbersThatAreNotTheQuantity')]
    public function a_number_in_the_quote_that_is_not_its_quantity_is_rejected(string $source, float $quantity, string $unit, string $reason): void
    {
        $problems = (new GroundingValidator)->problems($this->item([
            'quantity' => $quantity, 'unit' => $unit, 'source_text' => $source,
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

    /**
     * The checks must never reject what the golden cases say is correct: every
     * expected item, quoted as the line it sits on, has to pass.
     */
    #[Test]
    public function every_expected_item_in_the_golden_cases_is_grounded(): void
    {
        foreach (EvalCase::loadDirectory(__DIR__.'/../../../evals/cases') as $case) {
            foreach ($case->expected as $expected) {
                $line = array_find(explode("\n", $case->document), static fn (string $l): bool => str_contains(mb_strtolower($l), mb_strtolower($expected->sourceContains)));
                $this->assertIsString($line, "{$case->name}: no line contains \"{$expected->sourceContains}\"");

                $problems = (new GroundingValidator)->problems([
                    'trade' => $expected->trade->value,
                    'description' => $expected->sourceContains,
                    'quantity' => $expected->quantity->value,
                    'unit' => $expected->unit->value,
                    'spec_reference' => null,
                    'source_text' => trim($line),
                ], $case->document);

                $this->assertSame([], $problems, "{$case->name}: {$expected->describe()}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides): array
    {
        return $overrides + ['trade' => 'partitions', 'description' => 'Metal stud partition', 'unit' => 'm2', 'spec_reference' => 'K10/120'];
    }
}
