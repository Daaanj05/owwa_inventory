<?php

namespace Tests\Unit;

use App\Support\OwwaPdfBinaryMerger;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OwwaPdfBinaryMergerTest extends TestCase
{
    #[Test]
    public function it_returns_the_only_pdf_unchanged(): void
    {
        $pdf = $this->minimalPdf();

        $this->assertSame($pdf, OwwaPdfBinaryMerger::merge([$pdf]));
    }

    #[Test]
    public function it_rejects_empty_input(): void
    {
        $this->expectException(RuntimeException::class);
        OwwaPdfBinaryMerger::merge([]);
    }

    #[Test]
    public function it_merges_two_pdfs_when_a_merge_tool_is_available(): void
    {
        $first = $this->minimalPdf();
        $second = $this->minimalPdf();

        try {
            $merged = OwwaPdfBinaryMerger::merge([$first, $second]);
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        $this->assertStringStartsWith('%PDF', $merged);
        $this->assertNotSame($first, $merged);
    }

    protected function minimalPdf(): string
    {
        $objects = [
            '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj',
            '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj',
            '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< >> >>endobj',
            '4 0 obj<< /Length 0 >>stream'."\n".'endstream'."\n".'endobj',
        ];

        $body = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($body);
            $body .= $object."\n";
        }

        $xrefStart = strlen($body);
        $body .= "xref\n0 5\n";
        $body .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 4; $i++) {
            $body .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $body .= "trailer<< /Size 5 /Root 1 0 R >>\n";
        $body .= "startxref\n{$xrefStart}\n%%EOF\n";

        return $body;
    }
}
