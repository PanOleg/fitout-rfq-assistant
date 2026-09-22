<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use FitOut\Domain\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    #[Test]
    #[DataProvider('invalid')]
    public function it_rejects_non_positive_and_non_finite_values(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Quantity($value);
    }

    /** @return iterable<string, array{float}> */
    public static function invalid(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-3.0];
        yield 'infinity' => [INF];
        yield 'nan' => [NAN];
    }

    #[Test]
    public function it_compares_with_relative_tolerance(): void
    {
        $this->assertTrue((new Quantity(120.0))->isCloseTo(new Quantity(120.9)));
        $this->assertFalse((new Quantity(120.0))->isCloseTo(new Quantity(125.0)));
    }
}
