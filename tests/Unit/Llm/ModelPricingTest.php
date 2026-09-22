<?php

declare(strict_types=1);

namespace Tests\Unit\Llm;

use FitOut\Llm\ModelPricing;
use FitOut\Llm\Usage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelPricingTest extends TestCase
{
    #[Test]
    public function it_prices_input_output_and_cache_tokens(): void
    {
        // 1M input at $5 + 100k output at $25 + 1M cache reads at 10% of input
        $usage = new Usage(inputTokens: 1_000_000, outputTokens: 100_000, cacheReadTokens: 1_000_000);

        $this->assertSame(5.0 + 2.5 + 0.5, ModelPricing::costUsd('claude-opus-5', $usage));
    }

    #[Test]
    public function an_unknown_model_has_no_price_rather_than_a_wrong_one(): void
    {
        $this->assertNull(ModelPricing::costUsd('some-future-model', new Usage(10, 10)));
    }
}
