<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Anthropic\Client;
use FitOut\Evals\CaseScore;
use FitOut\Evals\EvalCase;
use FitOut\Evals\Scorer;
use FitOut\Extraction\LlmLineItemExtractor;
use FitOut\Llm\Anthropic\AnthropicLlmClient;
use Illuminate\Console\Command;

/**
 * Runs every case in evals/cases against the live model, uncached, and
 * writes the full report to storage/evals. This calls the API and costs
 * money — a few cents per run at the default settings.
 */
final class RunEvals extends Command
{
    protected $signature = 'rfq:eval
        {--case= : run only cases whose name contains this}
        {--model= : override RFQ_LLM_MODEL}
        {--effort= : override RFQ_LLM_EFFORT (low|medium|high|xhigh|max)}
        {--min-recall=0.9 : exit non-zero below this overall recall}';

    protected $description = 'Score line-item extraction against the golden cases';

    public function handle(Scorer $scorer): int
    {
        if (! is_string(config('services.anthropic.key')) || config('services.anthropic.key') === '') {
            $this->error('ANTHROPIC_API_KEY is not set. Evals run against the live model.');

            return self::FAILURE;
        }

        $model = (string) ($this->option('model') ?: config('rfq.llm.model'));
        $effort = (string) ($this->option('effort') ?: config('rfq.llm.effort'));
        /** @var 'low'|'medium'|'high'|'xhigh'|'max' $effort */
        $extractor = new LlmLineItemExtractor(new AnthropicLlmClient(new Client(apiKey: config('services.anthropic.key')), $model, $effort));

        $cases = array_values(array_filter(
            EvalCase::loadDirectory(base_path('evals/cases')),
            fn (EvalCase $c): bool => ! is_string($this->option('case')) || str_contains($c->name, $this->option('case')),
        ));

        $scores = [];
        foreach ($cases as $case) {
            $this->line("<comment>{$case->name}</comment> …");
            $scores[] = $score = $scorer->score($case, $extractor->extract($case->document));
            foreach ([...array_map(fn ($m) => "missed: {$m}", $score->missed), ...array_map(fn ($u) => "unexpected: {$u}", $score->unexpected), ...array_map(fn ($w) => "no warning about: {$w}", $score->unflagged)] as $problem) {
                $this->line("    <fg=red>{$problem}</>");
            }
        }

        $this->table(
            ['case', 'precision', 'recall', 'attempts', 'rejected', 'tokens in/out', 'cost $', 'ms', ''],
            array_map(fn (CaseScore $s): array => [
                $s->case,
                number_format($s->precision(), 2),
                number_format($s->recall(), 2),
                $s->result->attempts,
                count($s->result->rejected),
                $s->result->usage->inputTokens.' / '.$s->result->usage->outputTokens,
                number_format($s->result->costUsd() ?? 0, 4),
                $s->result->durationMs,
                $s->passed() ? '✓' : '✗',
            ], $scores),
        );

        $matched = array_sum(array_map(fn (CaseScore $s) => $s->matched, $scores));
        $expected = array_sum(array_map(fn (CaseScore $s) => $s->expected, $scores));
        $extracted = array_sum(array_map(fn (CaseScore $s) => $s->extracted, $scores));
        $recall = $expected === 0 ? 1.0 : $matched / $expected;
        $precision = $extracted === 0 ? 1.0 : $matched / $extracted;
        $cost = array_sum(array_map(fn (CaseScore $s) => $s->result->costUsd() ?? 0, $scores));

        $this->info(sprintf('%s @ %s, prompt %s — precision %.2f, recall %.2f, cost $%.4f', $model, $effort, $scores[0]->result->promptVersion ?? '-', $precision, $recall, $cost));

        $path = storage_path('evals/'.now()->format('Ymd-His')."-{$model}-{$effort}.json");
        @mkdir(dirname($path), recursive: true);
        file_put_contents($path, json_encode(array_map(fn (CaseScore $s): array => [
            'case' => $s->case, 'precision' => $s->precision(), 'recall' => $s->recall(),
            'missed' => $s->missed, 'unexpected' => $s->unexpected, 'unflagged' => $s->unflagged,
            'result' => $s->result->toArray(),
        ], $scores), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Report: {$path}");

        return $recall >= (float) $this->option('min-recall') ? self::SUCCESS : self::FAILURE;
    }
}
