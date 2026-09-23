<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tender;
use App\Models\TenderStatus;
use FitOut\Ingestion\DocumentReader;
use FitOut\Ingestion\UnreadableDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns an uploaded file into the tender's text, in a worker rather than the
 * web request: OCR of a long scan takes minutes, and poppler and tesseract
 * parse untrusted files — that belongs in a process that can be isolated and
 * killed, not in the one answering HTTP.
 */
final class ReadTenderDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Unreadable files do not become readable on retry; a crashed worker is the only reason for a second go. */
    public int $tries = 2;

    public int $timeout;

    public function __construct(public readonly string $tenderId)
    {
        $this->timeout = (int) config('rfq.queue.read_timeout');
        $this->onQueue(config('rfq.queue.reading'));
    }

    public function uniqueId(): string
    {
        return $this->tenderId;
    }

    public function handle(DocumentReader $reader): void
    {
        $tender = Tender::query()->find($this->tenderId);
        if ($tender === null || $tender->status !== TenderStatus::Reading || $tender->source_path === null) {
            return;
        }

        /** @var 'pdf'|'xlsx'|'csv'|'txt' $format validated on upload */
        $format = strtolower(pathinfo($tender->source_path, PATHINFO_EXTENSION));

        try {
            $read = $reader->read(Storage::disk('local')->path($tender->source_path), $format);
        } catch (UnreadableDocument $e) {
            $tender->update(['status' => TenderStatus::Failed, 'failure' => $e->getMessage()]);

            return;
        }

        if (mb_strlen($read->text) > config('rfq.max_document_chars')) {
            $tender->update(['status' => TenderStatus::Failed, 'failure' => 'The file contains more than '.config('rfq.max_document_chars').' characters of text.']);

            return;
        }

        $tender->update(['document' => $read->text, 'read_by' => $read->method, 'status' => TenderStatus::Pending]);
        ExtractTender::dispatch($tender->id);
    }

    public function failed(Throwable $e): void
    {
        Tender::query()->whereKey($this->tenderId)->update([
            'status' => TenderStatus::Failed,
            'failure' => 'The file could not be read: '.$e->getMessage(),
        ]);
    }
}
