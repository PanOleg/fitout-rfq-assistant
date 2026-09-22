<?php

declare(strict_types=1);

namespace FitOut\Evals;

/**
 * One eval run (a model at an effort level over every case), reduced to the
 * numbers a model or effort choice is made on: quality, cost and latency.
 */
final readonly class RunSummary
{
    public function __construct(
        public string $model,
        public string $effort,
        public int $cases,
        public int $passed,
        public float $precision,
        public float $recall,
        public int $rejected,
        public float $costUsd,
        public int $totalMs,
    ) {}

    /** @param non-empty-list<CaseScore> $scores */
    public static function of(string $model, string $effort, array $scores): self
    {
        $sum = static fn (callable $f): int|float => array_sum(array_map($f, $scores));
        $matched = $sum(static fn (CaseScore $s): int => $s->matched);
        $expected = $sum(static fn (CaseScore $s): int => $s->expected);
        $extracted = $sum(static fn (CaseScore $s): int => $s->extracted);

        return new self(
            model: $model,
            effort: $effort,
            cases: count($scores),
            passed: count(array_filter($scores, static fn (CaseScore $s): bool => $s->passed())),
            precision: $extracted === 0 ? 1.0 : $matched / $extracted,
            recall: $expected === 0 ? 1.0 : $matched / $expected,
            rejected: (int) $sum(static fn (CaseScore $s): int => count($s->result->rejected)),
            costUsd: (float) $sum(static fn (CaseScore $s): float => $s->result->costUsd() ?? 0.0),
            totalMs: (int) $sum(static fn (CaseScore $s): int => $s->result->durationMs),
        );
    }

    /** @param list<self> $runs */
    public static function markdown(array $runs, string $heading): string
    {
        $rows = array_map(static fn (self $r): string => sprintf(
            '| %s | %s | %d/%d | %.2f | %.2f | %d | $%.4f | $%.4f | %.1f s |',
            $r->model, $r->effort, $r->passed, $r->cases, $r->precision, $r->recall, $r->rejected,
            $r->costUsd, $r->costUsd / $r->cases, $r->totalMs / $r->cases / 1000,
        ), $runs);

        return "## {$heading}\n\n"
            ."| model | effort | cases passed | precision | recall | rejected | cost | cost / case | latency / case |\n"
            ."|---|---|---|---|---|---|---|---|---|\n"
            .implode("\n", $rows)."\n";
    }
}
