<?php

declare(strict_types=1);

namespace FitOut\Suppliers;

use FitOut\Domain\Supplier;

interface SupplierDirectory
{
    /** @return list<Supplier> */
    public function all(): array;
}
