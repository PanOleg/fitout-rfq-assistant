<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Evals\EvalCaseDrafter;
use App\Models\Tender;
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
        if ($tender === null || $tender->document === null) {
            $this->error($tender === null ? 'No such tender.' : 'The tender has not been read yet.');

            return self::FAILURE;
        }

        $drafter = new EvalCaseDrafter(is_string($this->option('dir')) ? $this->option('dir') : (string) config('rfq.evals.drafts_dir'));
        $name = $drafter->draft($tender, (string) $this->argument('slug'));

        $this->info("Draft written: {$drafter->directory()}/{$name}.*");
        $this->line(<<<TEXT
            Before it becomes a case:
              1. Anonymise {$name}.txt — client, address, people, prices. Keep layout, units and quantities.
              2. Fill {$name}.expected.json by reading the document line by line (the add-eval-case skill),
                 or review the tender on /tenders/{id}/review, which writes the expectations from your decisions.
                 {$name}.model-output.md shows what the model did, to find its mistakes — do not copy from it.
              3. Move the .txt and .expected.json to evals/cases/ and run: php artisan test --filter=GroundingValidatorTest
            TEXT);

        return self::SUCCESS;
    }
}
