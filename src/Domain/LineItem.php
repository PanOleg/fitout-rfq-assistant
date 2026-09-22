<?php

declare(strict_types=1);

namespace FitOut\Domain;

use InvalidArgumentException;

/**
 * One measured item of work. `sourceText` is the verbatim fragment of the
 * document it came from — the thread that lets anyone (or any check) trace an
 * item back to what the client actually wrote.
 */
final readonly class LineItem
{
    public function __construct(
        public Trade $trade,
        public string $description,
        public Quantity $quantity,
        public Unit $unit,
        public string $sourceText,
        public ?string $specReference = null,
    ) {
        if (trim($description) === '') {
            throw new InvalidArgumentException('Line item description cannot be empty.');
        }
        if (trim($sourceText) === '') {
            throw new InvalidArgumentException('Line item must quote the text it came from.');
        }
    }

    /** @return array{trade: string, description: string, quantity: float, unit: string, spec_reference: ?string, source_text: string} */
    public function toArray(): array
    {
        return [
            'trade' => $this->trade->value,
            'description' => $this->description,
            'quantity' => $this->quantity->value,
            'unit' => $this->unit->value,
            'spec_reference' => $this->specReference,
            'source_text' => $this->sourceText,
        ];
    }

    /** @param array{trade: string, description: string, quantity: float|int, unit: string, spec_reference?: ?string, source_text: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            trade: Trade::from($data['trade']),
            description: $data['description'],
            quantity: new Quantity((float) $data['quantity']),
            unit: Unit::from($data['unit']),
            sourceText: $data['source_text'],
            specReference: $data['spec_reference'] ?? null,
        );
    }
}
