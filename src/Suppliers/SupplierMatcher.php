<?php

declare(strict_types=1);

namespace FitOut\Suppliers;

use FitOut\Domain\Supplier;
use FitOut\Domain\WorkPackage;

/**
 * Plain rules, no model: who does this trade, who works in this region, who
 * has delivered well. The choice of who gets invited to bid has to be
 * explainable to the client, and a sort order is.
 */
final readonly class SupplierMatcher
{
    public function __construct(
        private SupplierDirectory $directory,
        private int $bidders = 3,
    ) {}

    public function shortlist(WorkPackage $package, string $region): Shortlist
    {
        $eligible = array_values(array_filter(
            $this->directory->all(),
            static fn (Supplier $s): bool => $s->covers($package->trade) && $s->servesRegion($region),
        ));

        // Higher rating first; between equals prefer the specialist, then name for a stable order.
        usort($eligible, static fn (Supplier $a, Supplier $b): int => [$b->rating, count($a->trades), $a->name] <=> [$a->rating, count($b->trades), $b->name]);

        return new Shortlist($package, array_slice($eligible, 0, $this->bidders), $this->bidders);
    }
}
