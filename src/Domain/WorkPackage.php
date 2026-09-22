<?php

declare(strict_types=1);

namespace FitOut\Domain;

/** All line items of one trade — the unit that goes out to tender. */
final readonly class WorkPackage
{
    /** @param non-empty-list<LineItem> $items */
    public function __construct(
        public Trade $trade,
        public array $items,
    ) {}

    /**
     * @param  list<LineItem>  $items
     * @return list<self> in trade order, empty trades omitted
     */
    public static function groupByTrade(array $items): array
    {
        $packages = [];
        foreach (Trade::cases() as $trade) {
            $ofTrade = array_values(array_filter($items, static fn (LineItem $i): bool => $i->trade === $trade));
            if ($ofTrade !== []) {
                $packages[] = new self($trade, $ofTrade);
            }
        }

        return $packages;
    }
}
