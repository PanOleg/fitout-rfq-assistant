<?php

declare(strict_types=1);

namespace FitOut\Suppliers;

use FitOut\Domain\Supplier;
use FitOut\Domain\Trade;

final readonly class ArraySupplierDirectory implements SupplierDirectory
{
    /** @var list<Supplier> */
    private array $suppliers;

    /** @param list<array{id: string, name: string, email: string, trades: list<string>, regions: list<string>, rating: float}> $rows */
    public function __construct(array $rows)
    {
        $this->suppliers = array_map(static fn (array $r): Supplier => new Supplier(
            id: $r['id'],
            name: $r['name'],
            email: $r['email'],
            trades: array_map(Trade::from(...), $r['trades']),
            regions: $r['regions'],
            rating: $r['rating'],
        ), $rows);
    }

    public function all(): array
    {
        return $this->suppliers;
    }
}
