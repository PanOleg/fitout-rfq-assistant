<?php

declare(strict_types=1);

namespace Tests\Unit\Rfq;

use DateTimeImmutable;
use FitOut\Domain\LineItem;
use FitOut\Domain\Quantity;
use FitOut\Domain\Supplier;
use FitOut\Domain\Trade;
use FitOut\Domain\Unit;
use FitOut\Domain\WorkPackage;
use FitOut\Rfq\RfqComposer;
use FitOut\Suppliers\Shortlist;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RfqComposerTest extends TestCase
{
    #[Test]
    public function the_schedule_carries_every_validated_quantity_unchanged(): void
    {
        $package = new WorkPackage(Trade::Flooring, [
            new LineItem(Trade::Flooring, 'Carpet tiles 500x500', new Quantity(1250.5), Unit::SquareMetre, 'x', 'M50/010'),
            new LineItem(Trade::Flooring, 'Entrance matting', new Quantity(12), Unit::SquareMetre, 'x'),
        ]);
        $supplier = new Supplier('s1', 'Acme Floors', 'bids@acme.test', [Trade::Flooring], ['london'], 4.2);

        $draft = (new RfqComposer)->compose('Level 3, 10 Example Street', new DateTimeImmutable('2026-10-09'), new Shortlist($package, [$supplier], 3));

        $this->assertSame('RFQ — Level 3, 10 Example Street — Floor finishes', $draft->subject);
        $this->assertStringContainsString('1,250.5 m2', $draft->body);
        $this->assertStringContainsString('M50/010', $draft->body);
        $this->assertStringContainsString('12 m2', $draft->body);
        $this->assertStringContainsString('Friday 9 October 2026', $draft->body);
        $this->assertSame(2, $draft->shortfall);
    }
}
