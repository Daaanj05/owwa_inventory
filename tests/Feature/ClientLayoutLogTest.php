<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ClientLayoutLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_post_modal_layout_report(): void
    {
        Log::spy();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('owwa.client-layout-log'), [
            'url' => 'http://localhost/purchase-orders',
            'hasValidationError' => true,
            'deadSpacePx' => 420,
            'contentChildrenSum' => 300,
            'windowChildrenSum' => 500,
            'viewport' => ['w' => 1280, 'h' => 800],
            'window' => [
                'clientHeight' => 700,
                'scrollHeight' => 700,
                'className' => 'fi-modal-window owwa-po-modal',
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'owwa-modal-layout dead space'
                    && ($context['report']['deadSpacePx'] ?? null) === 420;
            });
    }

    public function test_guest_cannot_post_modal_layout_report(): void
    {
        $response = $this->postJson(route('owwa.client-layout-log'), [
            'deadSpacePx' => 100,
        ]);

        $response->assertUnauthorized();
    }
}
