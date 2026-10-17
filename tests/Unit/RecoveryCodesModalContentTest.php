<?php

namespace Tests\Unit;

use App\Support\RecoveryCodesModalContent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecoveryCodesModalContentTest extends TestCase
{
    #[Test]
    public function download_link_uses_txt_filename_and_plain_text_data_uri(): void
    {
        $html = (string) RecoveryCodesModalContent::copyAndDownloadPrompt([
            'abcde-fghij',
            'klmno-pqrst',
        ]);

        $this->assertStringContainsString('download="'.RecoveryCodesModalContent::FILENAME.'"', $html);
        $this->assertStringContainsString('data:text/plain;charset=utf-8,', $html);
        $this->assertStringContainsString(rawurlencode(implode(PHP_EOL, ['abcde-fghij', 'klmno-pqrst'])), $html);
    }
}
