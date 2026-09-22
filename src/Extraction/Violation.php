<?php

declare(strict_types=1);

namespace FitOut\Extraction;

/** An extracted item that did not survive validation, and why. */
final readonly class Violation
{
    /**
     * @param  array<string, mixed>  $item  the item exactly as the model returned it
     * @param  non-empty-list<string>  $problems
     */
    public function __construct(
        public int $index,
        public array $item,
        public array $problems,
    ) {}

    /** @return array{index: int, item: array<string, mixed>, problems: non-empty-list<string>} */
    public function toArray(): array
    {
        return ['index' => $this->index, 'item' => $this->item, 'problems' => $this->problems];
    }
}
