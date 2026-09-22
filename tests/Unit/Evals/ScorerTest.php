<?php

declare(strict_types=1);

namespace Tests\Unit\Evals;

use FitOut\Domain\LineItem;
use FitOut\Domain\Quantity;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use FitOut\Evals\EvalCase;
use FitOut\Evals\ExpectedItem;
use FitOut\Evals\Scorer;
use FitOut\Extraction\ExtractionResult;
use FitOut\Llm\Usage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    #[Test]
    public function it_matches_one_to_one_and_reports_misses_extras_and_missing_warnings(): void
    {
        $case = new EvalCase('case', 'doc', [
            new ExpectedItem(Trade::Plumbing, new Quantity(4), Unit::Number, 'pans'),
            new ExpectedItem(Trade::Plumbing, new Quantity(4), Unit::Number, 'basins'),
        ], ['matting']);

        $score = (new Scorer)->score($case, $this->extraction([
            $this->item(Trade::Plumbing, 4, 'replace 4 nr pans'),
            $this->item(Trade::Plumbing, 4, 'replace 4 nr pans'), // duplicate does not satisfy "basins"
            $this->item(Trade::Electrical, 22, 'floor boxes x 22'),
        ], warnings: ['Carpet quantity needs calculating']));

        $this->assertSame(1, $score->matched);
        $this->assertSame(1 / 3, $score->precision());
        $this->assertSame(0.5, $score->recall());
        $this->assertSame(['plumbing 4 nr ("basins")'], $score->missed);
        $this->assertCount(2, $score->unexpected);
        $this->assertSame(['matting'], $score->unflagged);
        $this->assertFalse($score->passed());
    }

    #[Test]
    public function every_fixture_has_expectations_that_load(): void
    {
        $cases = EvalCase::loadDirectory(__DIR__.'/../../../evals/cases');

        $this->assertGreaterThanOrEqual(5, count($cases));
        foreach ($cases as $case) {
            foreach ($case->expected as $expected) {
                $this->assertStringContainsStringIgnoringCase($expected->sourceContains, $case->document, "{$case->name}: expectation must point at text that exists");
            }
        }
    }

    /**
     * @param  list<LineItem>  $items
     * @param  list<string>  $warnings
     */
    private function extraction(array $items, array $warnings = []): ExtractionResult
    {
        return new ExtractionResult($items, $warnings, [], 'claude-opus-5', 'v1', new Usage, 1, 0);
    }

    private function item(Trade $trade, float $qty, string $source): LineItem
    {
        return new LineItem($trade, 'x', new Quantity($qty), Unit::Number, $source);
    }
}
