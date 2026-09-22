<?php

declare(strict_types=1);

namespace FitOut\Extraction;

use Psr\SimpleCache\CacheInterface;

/**
 * Contractors re-upload the same BoQ constantly — revised drawings, a second
 * estimator, a retry after a timeout. Identical input under the same prompt
 * version and model gets the stored result instead of a second bill.
 *
 * The key includes the prompt and validator versions, so changing either
 * invalidates the cache by construction; nobody has to remember to flush it.
 */
final readonly class CachingLineItemExtractor implements LineItemExtractor
{
    /** @param string $modelKey everything about the model call that changes the output, e.g. "claude-opus-5:medium" */
    public function __construct(
        private LineItemExtractor $inner,
        private CacheInterface $cache,
        private string $modelKey,
        private int $ttlSeconds = 30 * 24 * 3600,
    ) {}

    public function extract(string $document, string $context = ''): ExtractionResult
    {
        $key = $this->key($context."\0".$document);

        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            /** @var array<string, mixed> $hit */
            return ExtractionResult::fromArray($hit)->servedFromCache();
        }

        $result = $this->inner->extract($document, $context);

        // Only clean results are worth replaying; a result with rejected items
        // should get a fresh attempt next time.
        if ($result->rejected === []) {
            $this->cache->set($key, $result->toArray(), $this->ttlSeconds);
        }

        return $result;
    }

    private function key(string $document): string
    {
        $normalised = (string) preg_replace('/\s+/u', ' ', trim($document));

        return 'extraction:'.hash('sha256', ExtractionPrompt::VERSION."\0".GroundingValidator::VERSION."\0".$this->modelKey."\0".$normalised);
    }
}
