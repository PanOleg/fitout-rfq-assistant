<?php

declare(strict_types=1);

namespace FitOut\Ingestion;

use RuntimeException;

/** An uploaded file that cannot be turned into text the extractor can quote. */
final class UnreadableDocument extends RuntimeException {}
