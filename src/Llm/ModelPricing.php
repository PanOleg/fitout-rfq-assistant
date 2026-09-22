<?php

declare(strict_types=1);

namespace FitOut\Llm;

/**
 * USD per million tokens. Kept in code rather than fetched: a price change
 * should be a reviewed commit, not a silent shift in reported costs.
 */
final class ModelPricing
{
    /** @var array<string, array{input: float, output: float}> */
    private const PRICES = [
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
    ];

    private const CACHE_READ_MULTIPLIER = 0.1;

    private const CACHE_WRITE_MULTIPLIER = 1.25;

    /** Returns null for an unknown model rather than a wrong number. */
    public static function costUsd(string $model, Usage $usage): ?float
    {
        $price = self::PRICES[$model] ?? null;
        if ($price === null) {
            return null;
        }

        $input = $usage->inputTokens
            + $usage->cacheReadTokens * self::CACHE_READ_MULTIPLIER
            + $usage->cacheWriteTokens * self::CACHE_WRITE_MULTIPLIER;

        return round(($input * $price['input'] + $usage->outputTokens * $price['output']) / 1_000_000, 6);
    }
}
