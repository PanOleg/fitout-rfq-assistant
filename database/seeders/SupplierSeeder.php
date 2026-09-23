<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

/** Loads the fictional demo suppliers; safe to run again. */
class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{id: string, name: string, email: string, trades: list<string>, regions: list<string>, rating: float}> $rows */
        $rows = require database_path('data/suppliers.php');

        foreach ($rows as $row) {
            Supplier::query()->updateOrCreate(['id' => $row['id']], $row);
        }
    }
}
