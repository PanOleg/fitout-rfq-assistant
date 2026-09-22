<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tender;
use Database\Seeders\SupplierSeeder;
use FitOut\Extraction\ExtractionPrompt;
use FitOut\Ingestion\DocumentReader;
use FitOut\Llm\Exceptions\LlmRefused;
use FitOut\Llm\LlmClient;
use FitOut\Llm\StructuredRequest;
use FitOut\Llm\StructuredResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MinimalPdf;
use Tests\Support\ScriptedLlmClient;
use Tests\TestCase;

final class TenderApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = SupplierSeeder::class;

    private const DOCUMENT = <<<'DOC'
    LEVEL 3 CAT A+ FIT-OUT — SCHEDULE OF WORKS
    K10/120  Metal stud partition, 2x15mm board each side    186 m2
    M50/010  Carpet tiles 500x500, open plan                  640 m2
    M50/020  Entrance matting, recessed                        12 m2
    DOC;

    private const ANSWER = [
        'items' => [
            ['trade' => 'partitions', 'description' => 'Metal stud partition, 2x15mm board', 'quantity' => 186, 'unit' => 'm2', 'spec_reference' => 'K10/120', 'source_text' => 'Metal stud partition, 2x15mm board each side    186 m2'],
            ['trade' => 'flooring', 'description' => 'Carpet tiles 500x500', 'quantity' => 640, 'unit' => 'm2', 'spec_reference' => 'M50/010', 'source_text' => 'Carpet tiles 500x500, open plan                  640 m2'],
            ['trade' => 'flooring', 'description' => 'Entrance matting', 'quantity' => 12, 'unit' => 'm2', 'spec_reference' => 'M50/020', 'source_text' => 'Entrance matting, recessed                        12 m2'],
        ],
        'warnings' => [],
    ];

    #[Test]
    public function a_tender_is_extracted_into_trade_packages_with_cost_reported(): void
    {
        $this->app->instance(LlmClient::class, new ScriptedLlmClient(self::ANSWER));

        $id = $this->postJson('/api/tenders', $this->payload())->assertAccepted()->json('data.id');

        $this->getJson("/api/tenders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'extracted')
            ->assertJsonPath('data.packages.0.trade', 'partitions')
            ->assertJsonPath('data.packages.1.trade', 'flooring')
            ->assertJsonCount(2, 'data.packages.1.items')
            ->assertJsonPath('data.extraction.prompt_version', ExtractionPrompt::VERSION)
            ->assertJsonPath('data.extraction.cost_usd', 0.01) // 1000 in × $5/M + 200 out × $25/M
            ->assertJsonMissingPath('data.document');
    }

    #[Test]
    public function rfqs_go_to_the_best_suppliers_for_each_package(): void
    {
        $this->app->instance(LlmClient::class, new ScriptedLlmClient(self::ANSWER));
        $id = $this->postJson('/api/tenders', $this->payload())->json('data.id');

        $rfqs = $this->getJson("/api/tenders/{$id}/rfqs")->assertOk()->json('data');

        $this->assertSame(['partitions', 'flooring'], array_column($rfqs, 'trade'));
        $this->assertSame('Fairway Flooring', $rfqs[1]['recipients'][0]['name']);
        $this->assertStringContainsString('640 m2', $rfqs[1]['body']);
    }

    #[Test]
    public function repeating_a_request_with_the_same_idempotency_key_does_not_extract_twice(): void
    {
        $llm = new ScriptedLlmClient(self::ANSWER);
        $this->app->instance(LlmClient::class, $llm);

        $first = $this->postJson('/api/tenders', $this->payload(), ['Idempotency-Key' => 'upload-42'])->assertAccepted();
        $second = $this->postJson('/api/tenders', $this->payload(), ['Idempotency-Key' => 'upload-42'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertCount(1, $llm->requests);
        $this->assertSame(1, Tender::query()->count());
    }

    #[Test]
    public function a_pdf_upload_is_read_as_text_and_extracted(): void
    {
        $llm = new ScriptedLlmClient(self::ANSWER);
        $this->app->instance(LlmClient::class, $llm);
        $pdf = UploadedFile::fake()->createWithContent('schedule.pdf', MinimalPdf::withLines(explode("\n", self::DOCUMENT)));

        $payload = ['file' => $pdf] + $this->payload();
        unset($payload['document']);
        $id = $this->post('/api/tenders', $payload, ['Accept' => 'application/json'])->assertAccepted()->json('data.id');

        $this->assertMatchesRegularExpression('/Carpet tiles 500x500, open plan\s+640 m2/', $llm->requests[0]->messages[0]['content']);
        $this->getJson("/api/tenders/{$id}")
            ->assertJsonPath('data.source_filename', 'schedule.pdf')
            ->assertJsonCount(2, 'data.packages');
    }

    #[Test]
    public function a_scanned_pdf_is_refused_with_a_reason_where_ocr_is_not_installed(): void
    {
        $this->app->instance(DocumentReader::class, new DocumentReader(pdftoppm: false, tesseract: false));
        $payload = ['file' => UploadedFile::fake()->createWithContent('scan.pdf', MinimalPdf::withLines([]))] + $this->payload();
        unset($payload['document']);

        $this->post('/api/tenders', $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'The PDF has no text layer — it looks scanned — and OCR is not available on this server.');

        $this->assertSame(0, Tender::query()->count());
    }

    #[Test]
    public function reusing_an_idempotency_key_for_a_different_document_is_rejected(): void
    {
        $llm = new ScriptedLlmClient(self::ANSWER);
        $this->app->instance(LlmClient::class, $llm);

        $this->postJson('/api/tenders', $this->payload(), ['Idempotency-Key' => 'upload-42'])->assertAccepted();
        $this->postJson('/api/tenders', ['document' => self::DOCUMENT."\nM60/010  Emulsion to walls   900 m2"] + $this->payload(), ['Idempotency-Key' => 'upload-42'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This Idempotency-Key was already used with a different request. Use a new key for a new tender.');

        $this->assertSame(1, Tender::query()->count());
        $this->assertCount(1, $llm->requests);
    }

    #[Test]
    public function a_refusal_fails_the_tender_without_retrying(): void
    {
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function structured(StructuredRequest $request): StructuredResponse
            {
                throw new LlmRefused('Model declined the request (cyber).');
            }
        });

        $id = $this->postJson('/api/tenders', $this->payload())->json('data.id');

        $this->getJson("/api/tenders/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', 'Model declined the request (cyber).');
        $this->getJson("/api/tenders/{$id}/rfqs")->assertStatus(409);
    }

    #[Test]
    public function input_is_validated_before_anything_is_spent(): void
    {
        $this->postJson('/api/tenders', ['region' => 'atlantis', 'return_by' => '2020-01-01', 'document' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'region', 'return_by', 'document']);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['name' => 'Level 3, 10 Example Street', 'region' => 'london', 'return_by' => now()->addDays(14)->toDateString(), 'document' => self::DOCUMENT];
    }
}
