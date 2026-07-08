<?php

namespace Tests\Feature;

use App\Filament\Support\OwwaFormModalDefaults;
use Tests\TestCase;

class OwwaModalHeadingTest extends TestCase
{
    public function test_create_heading_uses_model_label_only(): void
    {
        $this->assertSame('Office', OwwaFormModalDefaults::createHeading('Office'));
    }

    public function test_create_heading_capitalizes_lowercase_label(): void
    {
        $this->assertSame('Office', OwwaFormModalDefaults::createHeading('office'));
    }

    public function test_edit_heading_prefixes_edit(): void
    {
        $this->assertSame('Edit Office', OwwaFormModalDefaults::editHeading('Office'));
    }

    public function test_edit_heading_capitalizes_lowercase_label(): void
    {
        $this->assertSame('Edit User', OwwaFormModalDefaults::editHeading('user'));
    }

    public function test_view_heading_uses_model_label_only(): void
    {
        $this->assertSame('Sub-Office/Department', OwwaFormModalDefaults::viewHeading('Sub-Office/Department'));
    }
}
