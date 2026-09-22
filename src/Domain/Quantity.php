<?php

declare(strict_types=1);

namespace FitOut\Domain;

use InvalidArgumentException;

final readonly class Quantity
{
    public float $value;

    public function __construct(float $value)
    {
        if (! is_finite($value) || $value <= 0) {
            throw new InvalidArgumentException("Quantity must be a positive number, got {$value}.");
        }

        $this->value = round($value, 3);
    }

    /** Relative tolerance, because "120.5 m2" and "120 m2" are the same line to a QS. */
    public function isCloseTo(self $other, float $tolerance = 0.01): bool
    {
        return abs($this->value - $other->value) <= $tolerance * max($this->value, $other->value);
    }
}
