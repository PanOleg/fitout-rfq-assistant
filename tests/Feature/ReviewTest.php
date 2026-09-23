<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\SupplierSeeder;
use FitOut\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ScriptedLlmClient;
use Tests\TestCase;

final class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = SupplierSeeder::class;

    private const DOCUMENT = <<<'DOC'
    LEVEL 3 — SCHEDULE OF WORKS
    K10/120  Metal stud partition, 2x15mm board each side    186 m2
    M50/010  Carpet tiles 500x500, open plan                  640 m2
    F-01     Carpet tiles to open plan, 3 floors at 420 m2 per floor
    DOC;

    private string $drafts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->drafts = sys_get_temp_dir().'/'.uniqid('drafts-', true);
        config(['rfq.evals.drafts_dir' => $this->drafts]);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->drafts}/*") ?: []);
        @rmdir($this->drafts);
        parent::tearDown();
    }

    #[Test]
    public function the_reviewer_sees_accepted_rejected_and_warnings(): void
    {
        $id = $this->extractedTender();

        $this->get("/tenders/{$id}/review")
            ->assertOk()
            ->assertSee('Metal stud partition, 2x15mm board each side')
            ->assertSee('rejected')
            ->assertSee('is a rate')
            ->assertSee('Carpet area needs calculating');
    }

    #[Test]
    public function decisions_become_the_packages_and_an_eval_case(): void
    {
        $id = $this->extractedTender();

        $this->post("/tenders/{$id}/review", ['rows' => [
            // Keep the partition, drop the carpet, and recover the rejected per-floor line
            // with the reviewer's own calculation — theirs to make.
            ['origin' => 'accepted', 'decision' => 'keep', 'trade' => 'partitions', 'description' => 'Metal stud partition', 'quantity' => '186', 'unit' => 'm2', 'source_text' => 'Metal stud partition, 2x15mm board each side    186 m2'],
            ['origin' => 'accepted', 'decision' => 'drop'],
            ['origin' => 'rejected', 'decision' => 'keep', 'trade' => 'flooring', 'description' => 'Carpet tiles, 3 floors', 'quantity' => '1260', 'unit' => 'm2', 'source_text' => 'Carpet tiles to open plan, 3 floors at 420 m2 per floor'],
            ['origin' => 'added', 'decision' => 'drop'],
        ], 'notes' => 'Model multiplied nothing, correctly; the total is ours.', 'reviewer' => 'Ann Estimator', 'version' => 0])
            ->assertRedirect("/tenders/{$id}/review")
            ->assertSessionHas('status', static fn (string $s): bool => str_contains($s, 'version 1 saved by Ann Estimator: 1 kept, 1 dropped, 1 recovered from rejected, 0 added.'));

        $this->getJson("/api/tenders/{$id}")
            ->assertJsonPath('data.review.items', 2)
            ->assertJsonPath('data.review.version', 1)
            ->assertJsonPath('data.review.reviewer', 'Ann Estimator')
            ->assertJsonPath('data.packages.1.trade', 'flooring')
            ->assertJsonPath('data.packages.1.items.0.quantity', 1260);

        $expected = json_decode((string) file_get_contents(glob("{$this->drafts}/*.expected.json")[0]), true);
        $this->assertSame(['partitions', 'flooring'], array_column($expected['items'], 'trade'), 'the eval case expects what the reviewer decided');
        $this->assertStringContainsString('Model multiplied nothing', (string) file_get_contents(glob("{$this->drafts}/*.model-output.md")[0]));
    }

    #[Test]
    public function a_kept_item_must_quote_the_document(): void
    {
        $id = $this->extractedTender();

        $this->post("/tenders/{$id}/review", ['rows' => [
            ['origin' => 'added', 'decision' => 'keep', 'trade' => 'ceilings', 'description' => 'Grid ceiling', 'quantity' => '500', 'unit' => 'm2', 'source_text' => 'Suspended ceiling 500 m2'],
        ], 'reviewer' => 'Ann', 'version' => 0])->assertSessionHasErrors('rows.0.source_text');

        $this->getJson("/api/tenders/{$id}")->assertJsonPath('data.review', null);
    }

    #[Test]
    public function a_save_based_on_an_older_version_is_refused_not_overwriting(): void
    {
        $id = $this->extractedTender();
        $keepPartition = ['origin' => 'accepted', 'decision' => 'keep', 'trade' => 'partitions', 'description' => 'Metal stud partition', 'quantity' => '186', 'unit' => 'm2', 'source_text' => 'Metal stud partition, 2x15mm board each side    186 m2'];

        // Ann and Bob both open version 0; Ann saves first.
        $this->post("/tenders/{$id}/review", ['rows' => [$keepPartition], 'reviewer' => 'Ann', 'version' => 0])->assertSessionHasNoErrors();
        $this->post("/tenders/{$id}/review", ['rows' => [['origin' => 'accepted', 'decision' => 'drop']], 'reviewer' => 'Bob', 'version' => 0])
            ->assertSessionHasErrors(['version' => 'Not saved: Ann saved a newer review (version 1) at '.now()->toDayDateTimeString().'. Reload to see it, then apply your changes again.']);

        $this->getJson("/api/tenders/{$id}")->assertJsonPath('data.review.version', 1)->assertJsonPath('data.review.items', 1);
        $this->get("/tenders/{$id}/review")->assertSee('Ann')->assertSee('History');
    }

    #[Test]
    public function a_review_needs_a_reviewer_name(): void
    {
        $id = $this->extractedTender();

        $this->post("/tenders/{$id}/review", ['rows' => [['origin' => 'accepted', 'decision' => 'drop']], 'version' => 0])->assertSessionHasErrors('reviewer');
    }

    #[Test]
    public function the_review_screen_takes_the_token_as_a_basic_password(): void
    {
        $id = $this->extractedTender();
        config(['rfq.access_token' => 'secret-token']);

        $this->get("/tenders/{$id}/review")->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->get("/tenders/{$id}/review", ['Authorization' => 'Basic '.base64_encode('estimator:secret-token')])->assertOk();
    }

    private function extractedTender(): string
    {
        $this->app->instance(LlmClient::class, new ScriptedLlmClient(...array_fill(0, 3, [
            'items' => [
                ['trade' => 'partitions', 'description' => 'Metal stud partition', 'quantity' => 186, 'unit' => 'm2', 'spec_reference' => 'K10/120', 'source_text' => 'Metal stud partition, 2x15mm board each side    186 m2'],
                ['trade' => 'flooring', 'description' => 'Carpet tiles', 'quantity' => 640, 'unit' => 'm2', 'spec_reference' => 'M50/010', 'source_text' => 'Carpet tiles 500x500, open plan                  640 m2'],
                ['trade' => 'flooring', 'description' => 'Carpet tiles', 'quantity' => 420, 'unit' => 'm2', 'spec_reference' => 'F-01', 'source_text' => 'Carpet tiles to open plan, 3 floors at 420 m2 per floor'],
            ],
            'warnings' => ['Carpet area needs calculating'],
        ])));

        return (string) $this->postJson('/api/tenders', ['name' => 'Level 3', 'region' => 'london', 'return_by' => now()->addMonth()->toDateString(), 'document' => self::DOCUMENT])->json('data.id');
    }
}
