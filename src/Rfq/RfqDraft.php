<?php

declare(strict_types=1);

namespace FitOut\Rfq;

use FitOut\Domain\Supplier;
use FitOut\Domain\Trade;

final readonly class RfqDraft
{
    /** @param list<Supplier> $recipients */
    public function __construct(
        public Trade $trade,
        public string $subject,
        public string $body,
        public array $recipients,
        public int $shortfall,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trade' => $this->trade->value,
            'subject' => $this->subject,
            'body' => $this->body,
            'recipients' => array_map(static fn (Supplier $s): array => ['id' => $s->id, 'name' => $s->name, 'email' => $s->email], $this->recipients),
            'bidder_shortfall' => $this->shortfall,
        ];
    }
}
