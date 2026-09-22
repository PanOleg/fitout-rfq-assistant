<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tender;
use FitOut\Domain\LineItem;
use FitOut\Extraction\Violation;
use Illuminate\Console\Command;

/**
 * Starts an eval case from a real tender, so the cases stop being only the
 * ones the author imagined. It writes a draft, never a finished case: the
 * document has to be anonymised and the expectations written by a person
 * reading it — the model's own output is saved next to it for reference and
 * must not be copied, or the case would grade the model against itself.
 *
 * Drafts go to evals/drafts/, which is git-ignored because before
 * anonymisation it holds a client's document.
 */
final class DraftEvalCase extends Command
{
    protected $signature = 'rfq:draft-case
        {tender : id of the tender to start from}
        {slug : what the case tests, e.g. "scanned-table" or "revision-clouds"}
        {--dir= : where to write the draft (default evals/drafts)}';

    protected $description = 'Draft an eval case from a real tender, for a person to anonymise and complete';

    public function handle(): int
    {
        $tender = Tender::query()->find($this->argument('tender'));
        if ($tender === null) {
            $this->error('No such tender.');

            return self::FAILURE;
        }

        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $this->argument('slug')));
        $dir = is_string($this->option('dir')) ? $this->option('dir') : base_path('evals/drafts');
        $name = sprintf('%02d-%s', $this->nextNumber($dir), trim($slug, '-'));
        @mkdir($dir, recursive: true);

        file_put_contents("{$dir}/{$name}.txt", $tender->document);
        file_put_contents("{$dir}/{$name}.expected.json", json_encode(['items' => [], 'warnings_about' => []], JSON_PRETTY_PRINT)."\n");
        file_put_contents("{$dir}/{$name}.model-output.md", $this->modelOutput($tender));

        $this->info("Draft written: {$dir}/{$name}.*");
        $this->line(<<<TEXT
            Before it becomes a case:
              1. Anonymise {$name}.txt — client, address, people, prices. Keep layout, units and quantities.
              2. Fill {$name}.expected.json by reading the document line by line (the add-eval-case skill).
                 {$name}.model-output.md shows what the model did, to find its mistakes — do not copy from it.
              3. Move the .txt and .expected.json to evals/cases/ and run: php artisan test --filter=GroundingValidatorTest
            TEXT);

        return self::SUCCESS;
    }

    private function nextNumber(string $drafts): int
    {
        $numbers = array_map(
            static fn (string $path): int => (int) basename($path),
            [...(glob(base_path('evals/cases').'/*.txt') ?: []), ...(glob("{$drafts}/*.txt") ?: [])],
        );

        return ($numbers === [] ? 0 : max($numbers)) + 1;
    }

    private function modelOutput(Tender $tender): string
    {
        $result = $tender->extractionResult();
        if ($result === null) {
            return "# Model output\n\nThe tender has not been extracted ({$tender->status->value}).\n";
        }

        $items = array_map(static fn (LineItem $i): string => "- {$i->trade->value} {$i->quantity->value} {$i->unit->value} — \"{$i->sourceText}\"", $result->items);
        $rejected = array_map(static fn (Violation $v): string => '- "'.($v->item['source_text'] ?? '?').'" — '.implode(' ', $v->problems), $result->rejected);
        $warnings = array_map(static fn (string $w): string => "- {$w}", $result->warnings);

        return "# Model output — reference only, do not copy into expectations\n\n"
            ."Model {$result->model}, prompt {$result->promptVersion}, read by ".($tender->read_by ?? 'text').".\n\n"
            ."## Accepted items\n\n".($items === [] ? '_none_' : implode("\n", $items))."\n\n"
            ."## Rejected by the checks\n\n".($rejected === [] ? '_none_' : implode("\n", $rejected))."\n\n"
            ."## Model warnings\n\n".($warnings === [] ? '_none_' : implode("\n", $warnings))."\n";
    }
}
