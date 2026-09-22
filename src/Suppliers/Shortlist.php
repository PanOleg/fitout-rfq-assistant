<?php

declare(strict_types=1);

namespace FitOut\Suppliers;

use FitOut\Domain\Supplier;
use FitOut\Domain\WorkPackage;

final readonly class Shortlist
{
    /** @param list<Supplier> $suppliers best first */
    public function __construct(
        public WorkPackage $package,
        public array $suppliers,
        public int $required,
    ) {}

    /** Competitive tendering needs enough bidders; say so when there aren't. */
    public function shortfall(): int
    {
        return max(0, $this->required - count($this->suppliers));
    }
}
