<?php

declare(strict_types=1);

namespace FitOut\Evals;

use FitOut\Domain\LineItem;
use FitOut\Domain\Quantity;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;

/**
 * What a correct extraction must contain. Matching is on the facts that get
 * priced — trade, unit, quantity — plus a fragment of the source line to tell
 * apart two items with the same numbers. Wording of the description is free.
 */
final readonly class ExpectedItem
{
    public function __construct(
        public Trade $trade,
        public Quantity $quantity,
        public Unit $unit,
        public string $sourceContains,
    ) {}

    public function matches(LineItem $item): bool
    {
        return $item->trade === $this->trade
            && $item->unit === $this->unit
            && $item->quantity->isCloseTo($this->quantity, 0.0001)
            && str_contains(mb_strtolower($item->sourceText), mb_strtolower($this->sourceContains));
    }

    public function describe(): string
    {
        return "{$this->trade->value} {$this->quantity->value} {$this->unit->value} (\"{$this->sourceContains}\")";
    }
}
