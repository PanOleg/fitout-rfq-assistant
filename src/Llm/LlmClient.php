<?php

declare(strict_types=1);

namespace FitOut\Llm;

use FitOut\Llm\Exceptions\LlmOutputTruncated;
use FitOut\Llm\Exceptions\LlmRefused;
use FitOut\Llm\Exceptions\LlmUnavailable;

/**
 * The only way the application talks to a language model. Callers describe
 * the JSON they want; adapters guarantee they get JSON of that shape or an
 * exception that says why not.
 */
interface LlmClient
{
    /**
     * @throws LlmRefused the model declined the request
     * @throws LlmOutputTruncated the answer hit max tokens — retrying as-is will not help
     * @throws LlmUnavailable transient: network, rate limit, overload — safe to retry later
     */
    public function structured(StructuredRequest $request): StructuredResponse;
}
