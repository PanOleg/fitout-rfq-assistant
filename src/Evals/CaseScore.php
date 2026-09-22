<?php

declare(strict_types=1);

namespace FitOut\Evals;

use FitOut\Extraction\ExtractionResult;

final readonly class CaseScore
{
    /**
     * @param  list<string>  $missed  expected items not found
     * @param  list<string>  $unexpected  extracted items no expectation matched
     * @param  list<string>  $unflagged  keywords that should have produced a warning but did not
     */
    public function __construct(
        public string $case,
        public int $matched,
        public int $expected,
        public int $extracted,
        public array $missed,
        public array $unexpected,
        public array $unflagged,
        public ExtractionResult $result,
    ) {}

    public function precision(): float
    {
        return $this->extracted === 0 ? ($this->expected === 0 ? 1.0 : 0.0) : $this->matched / $this->extracted;
    }

    public function recall(): float
    {
        return $this->expected === 0 ? 1.0 : $this->matched / $this->expected;
    }

    public function passed(): bool
    {
        return $this->missed === [] && $this->unexpected === [] && $this->unflagged === [];
    }
}
