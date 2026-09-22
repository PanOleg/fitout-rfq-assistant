<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use FitOut\Domain\LineItem;
use FitOut\Domain\Quantity;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use FitOut\Domain\WorkPackage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkPackageTest extends TestCase
{
    #[Test]
    public function it_groups_items_by_trade_in_site_order_and_skips_empty_trades(): void
    {
        $carpet = $this->item(Trade::Flooring, 'Carpet tiles');
        $stud = $this->item(Trade::Partitions, 'Metal stud partition');
        $vinyl = $this->item(Trade::Flooring, 'Safety vinyl');

        $packages = WorkPackage::groupByTrade([$carpet, $stud, $vinyl]);

        $this->assertSame([Trade::Partitions, Trade::Flooring], array_map(fn (WorkPackage $p) => $p->trade, $packages));
        $this->assertSame([$carpet, $vinyl], $packages[1]->items);
    }

    private function item(Trade $trade, string $description): LineItem
    {
        return new LineItem($trade, $description, new Quantity(10), Unit::SquareMetre, $description);
    }
}
