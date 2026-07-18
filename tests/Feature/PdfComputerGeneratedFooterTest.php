<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class PdfComputerGeneratedFooterTest extends TestCase
{
    public function test_computer_generated_footer_includes_generation_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:30:00', config('app.timezone')));

        $html = view('owwa.partials.pdf-computer-generated-footer', [
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('This is computer generated on July 17, 2026.', $html);
    }
}
