<?php

declare(strict_types=1);

namespace Tests\Unit\Extraction;

use FitOut\Extraction\GroundingValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroundingValidatorTest extends TestCase
{
    private const DOCUMENT = <<<'DOC'
    K10/120  Metal stud partition, 2 layers 15mm plasterboard each side,   1,250 m²
    M50/010  Carpet tiles 500x500 to open plan office — 842.5 sqm
    DOC;

    #[Test]
    public function a_verbatim_quote_with_a_stated_quantity_is_grounded(): void
    {
        $problems = (new GroundingValidator)->problems($this->item([
            'quantity' => 1250,
            'source_text' => 'Metal stud partition, 2 layers 15mm plasterboard each side, 1,250 m2',
        ]), self::DOCUMENT);

        $this->assertSame([], $problems, 'whitespace, case and m² vs m2 are not differences');
    }

    #[Test]
    public function a_paraphrased_source_is_rejected(): void
    {
        $problems = (new GroundingValidator)->problems($this->item([
            'source_text' => 'Metal stud partitions, double boarded, 1,250 m2',
            'quantity' => 1250,
        ]), self::DOCUMENT);

        $this->assertContains('source_text is not a verbatim quote from the document.', $problems);
    }

    #[Test]
    public function a_quantity_that_is_not_written_in_the_quote_is_rejected(): void
    {
        // 842.5 is in the line; 850 is a model "rounding up".
        $problems = (new GroundingValidator)->problems($this->item([
            'trade' => 'flooring',
            'quantity' => 850,
            'source_text' => 'Carpet tiles 500x500 to open plan office — 842.5 sqm',
        ]), self::DOCUMENT);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('does not appear in source_text', $problems[0]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides): array
    {
        return $overrides + ['trade' => 'partitions', 'description' => 'Metal stud partition', 'unit' => 'm2', 'spec_reference' => 'K10/120'];
    }
}
