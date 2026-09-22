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
        public int $errors,
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
            errors: count(array_filter($scores, static fn (CaseScore $s): bool => $s->result->error !== null)),
            costUsd: (float) $sum(static fn (CaseScore $s): float => $s->result->costUsd() ?? 0.0),
            totalMs: (int) $sum(static fn (CaseScore $s): int => $s->result->durationMs),
        );
    }

    /** @param list<self> $runs */
    public static function markdown(array $runs, string $heading): string
    {
        $rows = array_map(static fn (self $r): string => sprintf(
            '| %s | %s | %d/%d | %.2f | %.2f | %d | %d | $%.4f | $%.4f | %.1f s |',
            $r->model, $r->effort, $r->passed, $r->cases, $r->precision, $r->recall, $r->rejected, $r->errors,
            $r->costUsd, $r->costUsd / $r->cases, $r->totalMs / $r->cases / 1000,
        ), $runs);

        return "## {$heading}\n\n"
            ."| model | effort | cases passed | precision | recall | rejected | errors | cost | cost / case | latency / case |\n"
            ."|---|---|---|---|---|---|---|---|---|---|\n"
            .implode("\n", $rows)."\n";
    }

    /**
     * One row per setting over repeated runs: the mean and the worst run, so a
     * setting that is perfect on average but drops items one run in three is
     * visible as such.
     *
     * @param  list<non-empty-list<self>>  $groups  runs of one model/effort each
     */
    public static function spreadMarkdown(array $groups, string $heading): string
    {
        $mean = static fn (array $values): float => array_sum($values) / count($values);
        $rows = array_map(static function (array $runs) use ($mean): string {
            $first = $runs[0];
            $recall = array_map(static fn (self $r): float => $r->recall, $runs);
            $precision = array_map(static fn (self $r): float => $r->precision, $runs);
            $passed = array_map(static fn (self $r): int => $r->passed, $runs);

            return sprintf(
                '| %s | %s | %d | %d–%d/%d | %.3f (%.3f) | %.3f (%.3f) | %.1f | %d | $%.4f | %.1f s |',
                $first->model, $first->effort, count($runs),
                min($passed), max($passed), $first->cases,
                $mean($recall), min($recall), $mean($precision), min($precision),
                $mean(array_map(static fn (self $r): int => $r->rejected, $runs)),
                array_sum(array_map(static fn (self $r): int => $r->errors, $runs)),
                $mean(array_map(static fn (self $r): float => $r->costUsd / $r->cases, $runs)),
                $mean(array_map(static fn (self $r): float => $r->totalMs / $r->cases / 1000, $runs)),
            );
        }, $groups);

        return "## {$heading}\n\n"
            ."Recall and precision: mean over runs, worst run in brackets.\n\n"
            ."| model | effort | runs | cases passed | recall | precision | rejected / run | errors | cost / case | latency / case |\n"
            ."|---|---|---|---|---|---|---|---|---|---|\n"
            .implode("\n", $rows)."\n";
    }
}
