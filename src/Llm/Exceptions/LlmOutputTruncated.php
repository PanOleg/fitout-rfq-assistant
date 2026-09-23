<?php

declare(strict_types=1);

namespace FitOut\Llm\Exceptions;

use FitOut\Llm\Usage;
use Throwable;

/**
 * The answer hit max tokens. The tokens were still billed, so they travel
 * with the exception: a retry or a failed tender must not make them vanish
 * from the reported cost.
 */
final class LlmOutputTruncated extends LlmException
{
    public function __construct(
        string $message,
        public readonly Usage $usage = new Usage,
        public readonly string $model = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** The same failure, with earlier spend on the same task added. */
    public function plus(Usage $earlier): self
    {
        return new self($this->getMessage(), $earlier->plus($this->usage), $this->model, $this);
    }
}
