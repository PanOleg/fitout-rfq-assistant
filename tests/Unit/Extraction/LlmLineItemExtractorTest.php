<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Domain\Trade;
use FitOut\Extraction\ExtractionPrompt;
use FitOut\Extraction\LlmLineItemExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScriptedLlmClient;

final class LlmLineItemExtractorTest extends TestCase
{
    private const DOCUMENT = <<<'DOC'
    Level 3 fit-out
    Carpet tiles to open plan, 640 m2
    Suspended ceiling 600x600 grid, 710 m2
    DOC;

    private const CARPET = ['trade' => 'flooring', 'description' => 'Carpet tiles', 'quantity' => 640, 'unit' => 'm2', 'spec_reference' => null, 'source_text' => 'Carpet tiles to open plan, 640 m2'];

    private const CEILING = ['trade' => 'ceilings', 'description' => 'Grid ceiling 600x600', 'quantity' => 710, 'unit' => 'm2', 'spec_reference' => null, 'source_text' => 'Suspended ceiling 600x600 grid, 710 m2'];

    #[Test]
    public function grounded_items_are_accepted_in_one_call(): void
    {
        $llm = new ScriptedLlmClient(['items' => [self::CARPET, self::CEILING], 'warnings' => []]);

        $result = (new LlmLineItemExtractor($llm))->extract(self::DOCUMENT);

        $this->assertCount(2, $result->items);
        $this->assertSame(Trade::Ceilings, $result->items[1]->trade);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(ExtractionPrompt::VERSION, $result->promptVersion);
    }

    #[Test]
    public function an_ungrounded_item_is_sent_back_with_the_reason_and_repaired(): void
    {
        $invented = ['quantity' => 700, 'source_text' => 'Suspended ceiling, 700 m2'] + self::CEILING;
        $llm = new ScriptedLlmClient(
            ['items' => [self::CARPET, $invented], 'warnings' => []],
            ['items' => [self::CARPET, self::CEILING], 'warnings' => []],
        );

        $result = (new LlmLineItemExtractor($llm))->extract(self::DOCUMENT);

        $this->assertCount(2, $result->items);
        $this->assertSame([], $result->rejected);
        $this->assertSame(2, $result->attempts);
        $this->assertSame(2000, $result->usage->inputTokens, 'usage is summed across attempts');

        $repair = $llm->requests[1]->messages;
        $this->assertCount(3, $repair, 'the repair turn carries the previous answer and the violations');
        $this->assertStringContainsString('items[1]', $repair[2]['content']);
        $this->assertStringContainsString('not a verbatim quote', $repair[2]['content']);
    }

    #[Test]
    public function items_still_failing_after_the_last_repair_are_reported_not_hidden(): void
    {
        $invented = ['quantity' => 700] + self::CEILING;
        $answer = ['items' => [self::CARPET, $invented], 'warnings' => ['Ceiling area unclear']];
        $llm = new ScriptedLlmClient($answer, $answer, $answer);

        $result = (new LlmLineItemExtractor($llm, maxRepairs: 2))->extract(self::DOCUMENT);

        $this->assertSame(3, $result->attempts);
        $this->assertCount(1, $result->items);
        $this->assertCount(1, $result->rejected);
        $this->assertSame(1, $result->rejected[0]->index);
        $this->assertSame(['Ceiling area unclear'], $result->warnings);
    }
}
