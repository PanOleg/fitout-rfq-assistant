<?php

declare(strict_types=1);

namespace FitOut\Evals;

use FitOut\Domain\Quantity;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use InvalidArgumentException;

final readonly class EvalCase
{
    /**
     * @param  list<ExpectedItem>  $expected
     * @param  list<string>  $warningsAbout  lines the model must flag instead of guessing, by keyword
     */
    public function __construct(
        public string $name,
        public string $document,
        public array $expected,
        public array $warningsAbout,
    ) {}

    /** @return list<self> every NAME.txt with a NAME.expected.json next to it, sorted by name */
    public static function loadDirectory(string $directory): array
    {
        $files = glob(rtrim($directory, '/').'/*.txt') ?: [];
        sort($files);

        return array_map(self::load(...), $files);
    }

    public static function load(string $documentPath): self
    {
        $expectedPath = substr($documentPath, 0, -4).'.expected.json';
        if (! is_file($expectedPath)) {
            throw new InvalidArgumentException("Missing expectations for {$documentPath}.");
        }

        /** @var array{items: list<array{trade: string, quantity: float|int, unit: string, source_contains: string}>, warnings_about: list<string>} $spec */
        $spec = json_decode((string) file_get_contents($expectedPath), true, flags: JSON_THROW_ON_ERROR);

        return new self(
            name: basename($documentPath, '.txt'),
            document: (string) file_get_contents($documentPath),
            expected: array_map(static fn (array $i): ExpectedItem => new ExpectedItem(
                Trade::from($i['trade']),
                new Quantity((float) $i['quantity']),
                Unit::from($i['unit']),
                $i['source_contains'],
            ), $spec['items']),
            warningsAbout: $spec['warnings_about'],
        );
    }
}
