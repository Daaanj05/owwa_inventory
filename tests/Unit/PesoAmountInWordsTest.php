<?php

namespace Tests\Unit;

use App\Support\PesoAmountInWords;
use Tests\TestCase;

class PesoAmountInWordsTest extends TestCase
{
    public function test_format_capitalizes_each_word(): void
    {
        $this->assertSame(
            'One Thousand Eight Hundred Fifty Pesos Only',
            PesoAmountInWords::format(1850),
        );
    }

    public function test_format_capitalizes_centavos_and_hyphenated_words(): void
    {
        $this->assertSame(
            'One Hundred Twenty-Three Pesos And Forty-Five Centavos Only',
            PesoAmountInWords::format(123.45),
        );
    }
}
