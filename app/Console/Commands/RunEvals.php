<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Anthropic\Client;
use FitOut\Evals\CaseScore;
use FitOut\Evals\EvalCase;
use FitOut\Evals\RunSummary;
use FitOut\Evals\Scorer;
use FitOut\Extraction\ChunkedLineItemExtractor;
use FitOut\Extraction\ExtractionPrompt;
use FitOut\Extraction\GroundingValidator;
use FitOut\Extraction\LlmLineItemExtractor;
use FitOut\Llm\Anthropic\AnthropicLlmClient;
use Illuminate\Console\Command;

/**
 * Runs every case in evals/cases against the live model, uncached, and
 * writes the full report to storage/evals. This calls the API and costs
 * money — a few cents per case at the default settings.
 *
 * With several --model / --effort values it runs the whole grid and writes
 * a comparison table to evals/results/, which is committed: a default model
 * or effort is chosen from that table, not from intuition.
 */
final class RunEvals extends Command
{
    protected $signature = 'rfq:eval
        {--case= : run only cases whose name contains this}
        {--model=* : model(s) to run; defaults to RFQ_LLM_MODEL}
        {--effort=* : effort level(s) (low|medium|high|xhigh|max); defaults to RFQ_LLM_EFFORT}
        {--min-recall=0.9 : exit non-zero if any run is below this overall recall}';

    protected $description = 'Score line-item extraction against the golden cases';

    private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function handle(Scorer $scorer): int
    {
        if (! is_string(config('services.anthropic.key')) || config('services.anthropic.key') === '') {
            $this->error('ANTHROPIC_API_KEY is not set. Evals run against the live model.');

            return self::FAILURE;
        }

        /** @var list<string> $models */
        $models = $this->option('model') ?: [(string) config('rfq.llm.model')];
        /** @var list<string> $efforts */
        $efforts = $this->option('effort') ?: [(string) config('rfq.llm.effort')];
        if (($bad = array_diff($efforts, self::EFFORTS)) !== []) {
            $this->error('Unknown effort: '.implode(', ', $bad));

            return self::FAILURE;
        }

        $cases = array_values(array_filter(
            EvalCase::loadDirectory(base_path('evals/cases')),
            fn (EvalCase $c): bool => ! is_string($this->option('case')) || str_contains($c->name, $this->option('case')),
        ));
        if ($cases === []) {
            $this->error('No cases match.');

            return self::FAILURE;
        }

        $runs = [];
        foreach ($models as $model) {
            foreach ($efforts as $effort) {
                /** @var 'low'|'medium'|'high'|'xhigh'|'max' $effort */
                $runs[] = $this->evaluate($scorer, $model, $effort, $cases);
            }
        }

        $heading = sprintf('%s — prompt %s, validator %s, %d cases', now()->format('Y-m-d'), ExtractionPrompt::VERSION, GroundingValidator::VERSION, count($cases));
        $table = RunSummary::markdown($runs, $heading);
        $this->newLine();
        $this->line($table);

        if (count($runs) > 1) {
            $path = base_path('evals/results/'.now()->format('Ymd-His').'.md');
            @mkdir(dirname($path), recursive: true);
            file_put_contents($path, $table);
            $this->info("Comparison: {$path} (commit it with the decision it supports)");
        }

        return array_all($runs, fn (RunSummary $r): bool => $r->recall >= (float) $this->option('min-recall')) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  'low'|'medium'|'high'|'xhigh'|'max'  $effort
     * @param  non-empty-list<EvalCase>  $cases
     */
    private function evaluate(Scorer $scorer, string $model, string $effort, array $cases): RunSummary
    {
        $this->info("{$model} @ {$effort}");
        $llm = new LlmLineItemExtractor(new AnthropicLlmClient(new Client(apiKey: config('services.anthropic.key')), $model, $effort));

        $scores = [];
        foreach ($cases as $case) {
            $this->line("  <comment>{$case->name}</comment> …");
            $extractor = new ChunkedLineItemExtractor($llm, $case->chunkChars ?? config('rfq.llm.chunk_chars'));
            $scores[] = $score = $scorer->score($case, $extractor->extract($case->document));
            foreach ([...array_map(fn ($m) => "missed: {$m}", $score->missed), ...array_map(fn ($u) => "unexpected: {$u}", $score->unexpected), ...array_map(fn ($w) => "no warning about: {$w}", $score->unflagged)] as $problem) {
                $this->line("      <fg=red>{$problem}</>");
            }
        }

        $path = storage_path('evals/'.now()->format('Ymd-His')."-{$model}-{$effort}.json");
        @mkdir(dirname($path), recursive: true);
        file_put_contents($path, json_encode(array_map(fn (CaseScore $s): array => [
            'case' => $s->case, 'precision' => $s->precision(), 'recall' => $s->recall(),
            'missed' => $s->missed, 'unexpected' => $s->unexpected, 'unflagged' => $s->unflagged,
            'result' => $s->result->toArray(),
        ], $scores), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("  Report: {$path}");

        return RunSummary::of($model, $effort, $scores);
    }
}
