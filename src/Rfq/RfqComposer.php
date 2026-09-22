<?php

declare(strict_types=1);

namespace FitOut\Rfq;

use DateTimeImmutable;
use FitOut\Domain\LineItem;
use FitOut\Suppliers\Shortlist;

/**
 * Builds the request-for-quotation text from a template. Deliberately not
 * generated: the schedule of quantities is contractual, and every number in
 * it must be the number that was validated — not a model's retelling of it.
 */
final class RfqComposer
{
    public function compose(string $projectName, DateTimeImmutable $returnBy, Shortlist $shortlist): RfqDraft
    {
        $trade = $shortlist->package->trade;

        $rows = array_map(static fn (LineItem $item, int $n): string => sprintf(
            '%3d. %-48s %10s %-4s %s',
            $n + 1,
            mb_strimwidth($item->description, 0, 48, '…'),
            rtrim(rtrim(number_format($item->quantity->value, 3, '.', ','), '0'), '.'),
            $item->unit->value,
            $item->specReference ?? '',
        ), $shortlist->package->items, array_keys($shortlist->package->items));

        $body = implode("\n", [
            'Dear estimating team,',
            '',
            "We invite you to quote for the {$trade->label()} package on {$projectName}.",
            'Please price the schedule below, stating any exclusions and your lead time.',
            '',
            ...$rows,
            '',
            'Quantities are provisional and to be verified against the drawings.',
            'Please return your quotation by '.$returnBy->format('l j F Y').'.',
        ]);

        return new RfqDraft(
            trade: $trade,
            subject: "RFQ — {$projectName} — {$trade->label()}",
            body: $body,
            recipients: $shortlist->suppliers,
            shortfall: $shortlist->shortfall(),
        );
    }
}
