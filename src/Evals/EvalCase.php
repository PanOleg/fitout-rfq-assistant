<?php

declare(strict_types=1);

namespace FitOut\Evals;

use FitOut\Domain\Quantity;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use FitOut\Ingestion\DocumentReader;
use InvalidArgumentException;

final readonly class EvalCase
{
    /**
     * @param  list<ExpectedItem>  $expected
     * @param  list<string>  $warningsAbout  lines the model must flag instead of guessing, by keyword
     * @param  ?positive-int  $chunkChars  read the document in pieces this size, to test long-document behaviour on a short case
     */
    public function __construct(
        public string $name,
        public string $document,
        public array $expected,
        public array $warningsAbout,
        public ?int $chunkChars = null,
        public string $format = 'txt',
    ) {}

    /**
     * Every NAME.expected.json with its document next to it — NAME.txt, or
     * NAME.pdf / .xlsx / .csv read through $reader, so file cases go through
     * the same ingestion as uploads. Sorted by name.
     *
     * @return list<self>
     */
    public static function loadDirectory(string $directory, ?DocumentReader $reader = null): array
    {
        $specs = glob(rtrim($directory, '/').'/*.expected.json') ?: [];
        sort($specs);

        return array_map(static fn (string $spec): self => self::load($spec, $reader), $specs);
    }

    public static function load(string $expectedPath, ?DocumentReader $reader = null): self
    {
        $stem = substr($expectedPath, 0, -strlen('.expected.json'));
        $documentPath = array_find(array_map(static fn (string $ext): string => "{$stem}.{$ext}", DocumentReader::FORMATS), static fn (string $path): bool => is_file($path))
            ?? throw new InvalidArgumentException("No document for {$expectedPath}.");
        /** @var 'pdf'|'xlsx'|'csv'|'txt' $format */
        $format = pathinfo($documentPath, PATHINFO_EXTENSION);

        if ($format === 'txt') {
            $document = (string) file_get_contents($documentPath);
        } elseif ($reader !== null) {
            $document = $reader->read($documentPath, $format)->text;
        } else {
            throw new InvalidArgumentException("{$documentPath} needs a DocumentReader.");
        }

        /** @var array{items: list<array{trade: string, quantity: float|int, unit: string, source_contains: string}>, warnings_about: list<string>, chunk_chars?: positive-int} $spec */
        $spec = json_decode((string) file_get_contents($expectedPath), true, flags: JSON_THROW_ON_ERROR);

        return new self(
            name: basename($stem),
            document: $document,
            expected: array_map(static fn (array $i): ExpectedItem => new ExpectedItem(
                Trade::from($i['trade']),
                new Quantity((float) $i['quantity']),
                Unit::from($i['unit']),
                $i['source_contains'],
            ), $spec['items']),
            warningsAbout: $spec['warnings_about'],
            chunkChars: $spec['chunk_chars'] ?? null,
            format: $format,
        );
    }
}
