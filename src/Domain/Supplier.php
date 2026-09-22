<?php

declare(strict_types=1);

namespace FitOut\Domain;

final readonly class Supplier
{
    /**
     * @param  list<Trade>  $trades
     * @param  list<string>  $regions  UK regions the supplier covers, e.g. "london", "south-east"
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public array $trades,
        public array $regions,
        public float $rating,
    ) {}

    public function covers(Trade $trade): bool
    {
        return in_array($trade, $this->trades, true);
    }

    public function servesRegion(string $region): bool
    {
        return in_array($region, $this->regions, true);
    }
}
