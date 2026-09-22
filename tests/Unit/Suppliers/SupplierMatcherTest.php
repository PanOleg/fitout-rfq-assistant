<?php

declare(strict_types=1);

namespace Tests\Unit\Suppliers;

use FitOut\Domain\LineItem;
use FitOut\Domain\Quantity;
use FitOut\Domain\Supplier;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use FitOut\Domain\WorkPackage;
use FitOut\Suppliers\ArraySupplierDirectory;
use FitOut\Suppliers\SupplierMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupplierMatcherTest extends TestCase
{
    #[Test]
    public function it_shortlists_by_trade_and_region_best_rated_first_specialists_on_ties(): void
    {
        $matcher = new SupplierMatcher(new ArraySupplierDirectory([
            $this->row('generalist', ['flooring', 'decoration'], ['london'], 4.5),
            $this->row('specialist', ['flooring'], ['london'], 4.5),
            $this->row('top', ['flooring'], ['london', 'south-east'], 4.9),
            $this->row('wrong-region', ['flooring'], ['north-west'], 5.0),
            $this->row('wrong-trade', ['ceilings'], ['london'], 5.0),
        ]), bidders: 3);

        $shortlist = $matcher->shortlist($this->package(Trade::Flooring), 'london');

        $this->assertSame(['top', 'specialist', 'generalist'], array_map(fn (Supplier $s) => $s->id, $shortlist->suppliers));
        $this->assertSame(0, $shortlist->shortfall());
    }

    #[Test]
    public function it_reports_how_many_bidders_are_missing(): void
    {
        $matcher = new SupplierMatcher(new ArraySupplierDirectory([$this->row('only', ['ceilings'], ['london'], 4.0)]), bidders: 3);

        $this->assertSame(2, $matcher->shortlist($this->package(Trade::Ceilings), 'london')->shortfall());
    }

    private function package(Trade $trade): WorkPackage
    {
        return new WorkPackage($trade, [new LineItem($trade, 'Item', new Quantity(1), Unit::Item, 'Item 1')]);
    }

    /**
     * @param  list<string>  $trades
     * @param  list<string>  $regions
     * @return array{id: string, name: string, email: string, trades: list<string>, regions: list<string>, rating: float}
     */
    private function row(string $id, array $trades, array $regions, float $rating): array
    {
        return ['id' => $id, 'name' => ucfirst($id), 'email' => "{$id}@example.test", 'trades' => $trades, 'regions' => $regions, 'rating' => $rating];
    }
}
