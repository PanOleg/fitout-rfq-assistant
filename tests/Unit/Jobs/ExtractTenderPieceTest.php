<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ExtractTenderPiece;
use Illuminate\Queue\Middleware\RateLimited;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExtractTenderPieceTest extends TestCase
{
    #[Test]
    public function pieces_share_the_model_rate_limit_and_run_on_the_extraction_queue(): void
    {
        $job = new ExtractTenderPiece('01tender', 0);

        $this->assertSame('extraction', $job->queue);
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
        $this->assertGreaterThan(now()->addMinutes(30), $job->retryUntil(), 'a 429 storm is waited out, not failed on');
    }
}
