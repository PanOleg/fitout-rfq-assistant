<?php

declare(strict_types=1);

namespace FitOut\Evals;

use FitOut\Domain\LineItem;
use FitOut\Extraction\ExtractionResult;

final class Scorer
{
    public function score(EvalCase $case, ExtractionResult $result): CaseScore
    {
        $unused = $result->items;
        $missed = [];

        // Greedy one-to-one matching: an extracted item can satisfy only one expectation.
        foreach ($case->expected as $expected) {
            $hit = array_find_key($unused, static fn (LineItem $item): bool => $expected->matches($item));
            if ($hit === null) {
                $missed[] = $expected->describe();
            } else {
                unset($unused[$hit]);
            }
        }

        $warnings = mb_strtolower(implode("\n", $result->warnings));
        $unflagged = array_values(array_filter(
            $case->warningsAbout,
            static fn (string $keyword): bool => ! str_contains($warnings, mb_strtolower($keyword)),
        ));

        return new CaseScore(
            case: $case->name,
            matched: count($case->expected) - count($missed),
            expected: count($case->expected),
            extracted: count($result->items),
            missed: $missed,
            unexpected: array_values(array_map(
                static fn (LineItem $i): string => "{$i->trade->value} {$i->quantity->value} {$i->unit->value} (\"{$i->sourceText}\")",
                $unused,
            )),
            unflagged: $unflagged,
            result: $result,
        );
    }
}
