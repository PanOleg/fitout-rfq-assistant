<?php

declare(strict_types=1);

namespace App\Suppliers;

use App\Models\Supplier as SupplierRow;
use FitOut\Domain\Supplier;
use FitOut\Domain\Trade;
use FitOut\Suppliers\SupplierDirectory;

/** The suppliers table behind the directory port. */
final class EloquentSupplierDirectory implements SupplierDirectory
{
    public function all(): array
    {
        return array_values(array_map(static fn (SupplierRow $row): Supplier => new Supplier(
            id: $row->id,
            name: $row->name,
            email: $row->email,
            trades: array_values(array_map(Trade::from(...), $row->trades)),
            regions: array_values($row->regions),
            rating: (float) $row->rating,
        ), SupplierRow::query()->orderBy('id')->get()->all()));
    }
}
