<?php

namespace Tests\Unit;

use App\Support\PdfSafeText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfSafeTextTest extends TestCase
{
    #[Test]
    public function it_replaces_em_dash_with_ascii_hyphen(): void
    {
        $this->assertSame(
            'Basketball - Sports equipment',
            PdfSafeText::normalize('Basketball — Sports equipment'),
        );
    }

    #[Test]
    public function it_normalizes_string_values_in_arrays(): void
    {
        $this->assertSame(
            [
                'description' => 'Chair - Office',
                'qty' => 2,
            ],
            PdfSafeText::normalizeArray([
                'description' => 'Chair – Office',
                'qty' => 2,
            ]),
        );
    }
}
