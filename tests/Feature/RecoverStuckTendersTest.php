<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tender;
use App\Models\TenderPiece;
use App\Models\TenderStatus;
use Database\Seeders\SupplierSeeder;
use FitOut\Extraction\ExtractionResult;
use FitOut\Llm\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecoverStuckTendersTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = SupplierSeeder::class;

    #[Test]
    public function a_tender_whose_pieces_finished_but_was_never_assembled_is_assembled(): void
    {
        $tender = $this->tender(TenderStatus::Extracting, minutesAgo: 20);
        TenderPiece::query()->create(['tender_id' => $tender->id, 'position' => 0, 'offset' => 0, 'length' => 10, 'status' => TenderPiece::DONE,
            'result' => (new ExtractionResult([], ['ok'], [], 'claude-opus-5', 'v2', new Usage, 1, 5))->toArray()]);

        $this->artisan('rfq:recover-stuck')->assertSuccessful();

        $this->assertSame(TenderStatus::Extracted, $tender->refresh()->status);
    }

    #[Test]
    public function a_file_that_has_been_reading_far_too_long_fails_with_a_reason(): void
    {
        $tender = $this->tender(TenderStatus::Reading, minutesAgo: 60);

        $this->artisan('rfq:recover-stuck')->assertSuccessful();

        $this->assertSame(TenderStatus::Failed, $tender->refresh()->status);
        $this->assertSame('Reading the file stalled. Upload it again.', $tender->failure);
    }

    #[Test]
    public function recent_tenders_are_left_alone(): void
    {
        $tender = $this->tender(TenderStatus::Extracting, minutesAgo: 1);

        $this->artisan('rfq:recover-stuck')->assertSuccessful();

        $this->assertSame(TenderStatus::Extracting, $tender->refresh()->status);
    }

    private function tender(TenderStatus $status, int $minutesAgo): Tender
    {
        $tender = Tender::query()->create(['name' => 'T', 'region' => 'london', 'return_by' => '2026-12-01', 'document' => 'Carpet tiles, 640 m2', 'status' => $status]);
        Tender::query()->whereKey($tender->id)->update(['updated_at' => Carbon::now()->subMinutes($minutesAgo)]);

        return $tender->refresh();
    }
}
