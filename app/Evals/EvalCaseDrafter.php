<?php

declare(strict_types=1);

namespace App\Evals;

use App\Models\Tender;
use FitOut\Domain\LineItem;
use FitOut\Extraction\Violation;

/**
 * Writes an eval case draft from a real tender to the git-ignored drafts
 * directory (before anonymisation it is a client's document).
 *
 * Expectations are either left empty for a person to write from the document,
 * or — after review — they are the reviewer's decisions: what a person said
 * the document contains, never the model's own output.
 */
final class EvalCaseDrafter
{
    public function __construct(private readonly string $directory) {}

    /**
     * @param  list<LineItem>|null  $expected  the reviewer's items; null leaves expectations empty
     * @return string the draft's name, e.g. "07-level-3-schedule"
     */
    public function draft(Tender $tender, string $slug, ?array $expected = null, string $notes = ''): string
    {
        $name = sprintf('%02d-%s', $this->nextNumber(), trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slug)), '-'));
        @mkdir($this->directory, recursive: true);

        $items = array_map(static fn (LineItem $i): array => [
            'trade' => $i->trade->value,
            'quantity' => $i->quantity->value,
            'unit' => $i->unit->value,
            'source_contains' => trim((string) preg_replace('/\s+/u', ' ', $i->sourceText)),
        ], $expected ?? []);

        file_put_contents("{$this->directory}/{$name}.txt", (string) $tender->document);
        file_put_contents("{$this->directory}/{$name}.expected.json", json_encode(['items' => $items, 'warnings_about' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents("{$this->directory}/{$name}.model-output.md", $this->modelOutput($tender).($notes === '' ? '' : "\n## Review\n\n{$notes}\n"));

        return $name;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private function nextNumber(): int
    {
        $numbers = array_map(
            static fn (string $path): int => (int) basename($path),
            [...(glob(base_path('evals/cases').'/*.txt') ?: []), ...(glob("{$this->directory}/*.txt") ?: [])],
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
