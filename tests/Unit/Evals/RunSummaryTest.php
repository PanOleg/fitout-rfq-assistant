<?php

declare(strict_types=1);

namespace Tests\Unit\Evals;

use FitOut\Evals\CaseScore;
use FitOut\Evals\RunSummary;
use FitOut\Extraction\ExtractionResult;
use FitOut\Llm\Usage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunSummaryTest extends TestCase
{
    #[Test]
    public function precision_and_recall_are_pooled_over_items_not_averaged_over_cases(): void
    {
        $summary = RunSummary::of('claude-sonnet-5', 'low', [
            $this->score(matched: 9, expected: 10, extracted: 9, ms: 4000),
            $this->score(matched: 1, expected: 2, extracted: 3, ms: 2000, unexpected: ['extra']),
        ]);

        $this->assertSame(10 / 12, $summary->precision);
        $this->assertSame(10 / 12, $summary->recall);
        $this->assertSame(0, $summary->passed, 'a missed item fails a case');
        $this->assertSame(6000, $summary->totalMs);
        $this->assertSame(0.008, round($summary->costUsd, 4)); // 2 × (1000 in × $2/M + 200 out × $10/M)

        $table = RunSummary::markdown([$summary], 'prompt v1');
        $this->assertStringContainsString('| claude-sonnet-5 | low | 0/2 | 0.83 | 0.83 | 0 | 0 | $0.0080 | $0.0040 | 3.0 s |', $table);
    }

    #[Test]
    public function repeated_runs_show_the_mean_and_the_worst_run(): void
    {
        $good = RunSummary::of('claude-sonnet-5', 'low', [$this->score(matched: 10, expected: 10, extracted: 10, ms: 2000)]);
        $bad = RunSummary::of('claude-sonnet-5', 'low', [$this->score(matched: 8, expected: 10, extracted: 8, ms: 4000)]);

        $table = RunSummary::spreadMarkdown([[$good, $bad]], 'grid');

        $this->assertStringContainsString('| claude-sonnet-5 | low | 2 | 0–1/1 | 0.900 (0.800) | 1.000 (1.000) | 0.0 | 0 | $0.0040 | 3.0 s |', $table);
    }

    /** @param list<string> $unexpected */
    private function score(int $matched, int $expected, int $extracted, int $ms, array $unexpected = []): CaseScore
    {
        $result = new ExtractionResult([], [], [], 'claude-sonnet-5', 'v1', new Usage(1000, 200), 1, $ms);

        return new CaseScore('c', $matched, $expected, $extracted, $matched < $expected ? ['missed'] : [], $unexpected, [], $result);
    }
}
