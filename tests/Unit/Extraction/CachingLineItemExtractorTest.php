<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Extraction\CachingLineItemExtractor;
use FitOut\Extraction\LlmLineItemExtractor;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScriptedLlmClient;

final class CachingLineItemExtractorTest extends TestCase
{
    private const DOCUMENT = "Carpet tiles to open plan, 640 m2\n";

    private const ANSWER = ['items' => [['trade' => 'flooring', 'description' => 'Carpet tiles', 'quantity' => 640, 'unit' => 'm2', 'spec_reference' => null, 'source_text' => 'Carpet tiles to open plan, 640 m2']], 'warnings' => []];

    #[Test]
    public function the_same_document_is_extracted_once(): void
    {
        $llm = new ScriptedLlmClient(self::ANSWER);
        $extractor = new CachingLineItemExtractor(new LlmLineItemExtractor($llm), new Repository(new ArrayStore), 'claude-opus-5:medium');

        $first = $extractor->extract(self::DOCUMENT);
        $second = $extractor->extract('  Carpet tiles to open plan,   640 m2 ');

        $this->assertCount(1, $llm->requests, 'whitespace-only differences hit the cache');
        $this->assertFalse($first->fromCache);
        $this->assertTrue($second->fromCache);
        $this->assertEquals($first->items, $second->items);
    }

    #[Test]
    public function a_different_model_key_is_a_different_entry(): void
    {
        $cache = new Repository(new ArrayStore);
        $llm = new ScriptedLlmClient(self::ANSWER, self::ANSWER);

        (new CachingLineItemExtractor(new LlmLineItemExtractor($llm), $cache, 'claude-opus-5:medium'))->extract(self::DOCUMENT);
        (new CachingLineItemExtractor(new LlmLineItemExtractor($llm), $cache, 'claude-opus-5:high'))->extract(self::DOCUMENT);

        $this->assertCount(2, $llm->requests);
    }

    #[Test]
    public function results_with_rejected_items_are_not_cached(): void
    {
        $bad = ['items' => [['quantity' => 999] + self::ANSWER['items'][0]], 'warnings' => []];
        $llm = new ScriptedLlmClient($bad, $bad, $bad, self::ANSWER);
        $extractor = new CachingLineItemExtractor(new LlmLineItemExtractor($llm), new Repository(new ArrayStore), 'm');

        $this->assertCount(1, $extractor->extract(self::DOCUMENT)->rejected);
        $this->assertCount(0, $extractor->extract(self::DOCUMENT)->rejected);
        $this->assertCount(4, $llm->requests);
    }
}
