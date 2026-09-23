<?php

declare(strict_types=1);

namespace FitOut\Domain;

use InvalidArgumentException;

/**
 * The words the trade check reads a quote with, for one language and market.
 * Data, not code: see Vocabulary/en-GB.php. A new market is a new file.
 */
final readonly class TradeVocabulary
{
    /**
     * @param  array<string, list<string>>  $trades  trade value => regex fragments, matched as whole words
     * @param  list<string>  $locations  words after which a noun is a place, not a trade
     */
    public function __construct(
        public string $name,
        private array $trades,
        public array $locations,
    ) {}

    /** A bundled vocabulary by name, e.g. "en-GB", or a path to a file of the same shape. */
    public static function load(string $nameOrPath = 'en-GB'): self
    {
        $path = is_file($nameOrPath) ? $nameOrPath : __DIR__."/Vocabulary/{$nameOrPath}.php";
        if (! is_file($path)) {
            throw new InvalidArgumentException("No trade vocabulary \"{$nameOrPath}\".");
        }

        /** @var array{trades: array<string, list<string>>, locations: list<string>} $data */
        $data = require $path;

        return new self(pathinfo($path, PATHINFO_FILENAME), $data['trades'], $data['locations']);
    }

    /** @return list<string> */
    public function words(Trade $trade): array
    {
        return $this->trades[$trade->value] ?? [];
    }
}
