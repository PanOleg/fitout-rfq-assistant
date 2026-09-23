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
    public function an_item_accepted_earlier_is_kept_when_the_repair_answer_leaves_it_out(): void
    {
        $invented = ['quantity' => 700, 'source_text' => 'Suspended ceiling, 700 m2'] + self::CEILING;
        $llm = new ScriptedLlmClient(
            ['items' => [self::CARPET, $invented], 'warnings' => ['Carpet colour to be confirmed']],
            // Asked for the complete list again, the model returns only the fixed ceiling.
            ['items' => [self::CEILING], 'warnings' => []],
        );

        $result = (new LlmLineItemExtractor($llm))->extract(self::DOCUMENT);

        $this->assertEqualsCanonicalizing([640.0, 710.0], array_map(static fn ($i): float => $i->quantity->value, $result->items), 'the carpet accepted in round 1 is not silently dropped');
        $this->assertSame(['Carpet colour to be confirmed'], $result->warnings, 'warnings from earlier rounds are kept too');
    }

    #[Test]
    public function a_repair_that_restates_an_accepted_item_replaces_it_rather_than_duplicating_it(): void
    {
        $invented = ['quantity' => 700, 'source_text' => 'Suspended ceiling, 700 m2'] + self::CEILING;
        $reworded = ['description' => 'Carpet tiles, open plan'] + self::CARPET;
        $llm = new ScriptedLlmClient(
            ['items' => [self::CARPET, $invented], 'warnings' => []],
            ['items' => [$reworded, self::CEILING], 'warnings' => []],
        );

        $result = (new LlmLineItemExtractor($llm))->extract(self::DOCUMENT);

        $this->assertCount(2, $result->items);
        $this->assertContains('Carpet tiles, open plan', array_map(static fn ($i): string => $i->description, $result->items));
    }

    #[Test]
    public function two_items_sharing_one_quote_are_both_kept(): void
    {
        $document = "- WCs: replace 4 nr pans and 4 nr basins in the gents, like for like\n";
        $quote = 'replace 4 nr pans and 4 nr basins in the gents, like for like';
        $pans = ['trade' => 'plumbing', 'description' => 'Replace WC pans', 'quantity' => 4, 'unit' => 'nr', 'spec_reference' => null, 'source_text' => $quote];
        $basins = ['description' => 'Replace basins'] + $pans;

        $result = (new LlmLineItemExtractor(new ScriptedLlmClient(['items' => [$pans, $basins], 'warnings' => []])))->extract($document);

        $this->assertSame(['Replace WC pans', 'Replace basins'], array_map(static fn ($i): string => $i->description, $result->items));
    }

    #[Test]
    public function a_repair_that_restates_one_of_two_items_sharing_a_quote_keeps_the_other(): void
    {
        $document = "- WCs: replace 4 nr pans and 4 nr basins in the gents, like for like\nSuspended ceiling 600x600 grid, 710 m2\n";
        $quote = 'replace 4 nr pans and 4 nr basins in the gents, like for like';
        $pans = ['trade' => 'plumbing', 'description' => 'Replace WC pans', 'quantity' => 4, 'unit' => 'nr', 'spec_reference' => null, 'source_text' => $quote];
        $basins = ['description' => 'Replace basins'] + $pans;
        $invented = ['quantity' => 700, 'source_text' => 'Suspended ceiling, 700 m2'] + self::CEILING;
        $llm = new ScriptedLlmClient(
            ['items' => [$pans, $basins, $invented], 'warnings' => []],
            // The repair answer returns the fixed ceiling and only one of the two.
            ['items' => [$basins, self::CEILING], 'warnings' => []],
        );

        $result = (new LlmLineItemExtractor($llm))->extract($document);

        $this->assertEqualsCanonicalizing(['Replace WC pans', 'Replace basins', 'Grid ceiling 600x600'], array_map(static fn ($i): string => $i->description, $result->items));
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
