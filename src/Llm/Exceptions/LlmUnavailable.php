<?php

declare(strict_types=1);

namespace FitOut\Llm\Exceptions;

/** Transient failure. The caller may retry with backoff. */
final class LlmUnavailable extends LlmException {}
