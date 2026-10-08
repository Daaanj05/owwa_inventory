<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalGenderAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_panels_use_a_local_image_for_the_user_avatar(): void
    {
        $male = User::factory()->create(['gender' => User::GENDER_MALE]);
        $female = User::factory()->create(['gender' => User::GENDER_FEMALE]);
        $unset = User::factory()->create(['gender' => null]);

        foreach (['admin', 'system-admin'] as $panelId) {
            Filament::setCurrentPanel(Filament::getPanel($panelId));

            $maleUrl = Filament::getUserAvatarUrl($male);
            $femaleUrl = Filament::getUserAvatarUrl($female);
            $unsetUrl = Filament::getUserAvatarUrl($unset);

            $this->assertStringContainsString('/images/avatar-male.svg', $maleUrl);
            $this->assertStringContainsString('/images/avatar-female.svg', $femaleUrl);
            $this->assertStringContainsString('/images/avatar-neutral.svg', $unsetUrl);
            $this->assertStringNotContainsString('ui-avatars.com', $maleUrl.$femaleUrl.$unsetUrl);
        }

        $this->assertFileExists(public_path('images/avatar-male.svg'));
        $this->assertFileExists(public_path('images/avatar-female.svg'));
        $this->assertFileExists(public_path('images/avatar-neutral.svg'));
    }
}
