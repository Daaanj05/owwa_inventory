<?php

namespace Tests\Feature;

use App\Services\RagContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RagContextApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_rag_context_requires_bearer_token(): void
    {
        config(['services.rag.context_token' => 'secret-token']);

        $this->getJson(route('rag.context'))
            ->assertUnauthorized();
    }

    public function test_rag_context_rejects_wrong_token(): void
    {
        config(['services.rag.context_token' => 'secret-token']);

        $this->withToken('wrong-token')
            ->getJson(route('rag.context'))
            ->assertUnauthorized();
    }

    public function test_rag_context_denies_when_token_not_configured(): void
    {
        config(['services.rag.context_token' => null]);

        $this->withToken('any-token')
            ->getJson(route('rag.context'))
            ->assertForbidden();
    }

    public function test_rag_context_returns_payload_with_valid_token(): void
    {
        config(['services.rag.context_token' => 'secret-token']);

        $this->mock(RagContextService::class, function ($mock): void {
            $mock->shouldReceive('buildContext')
                ->once()
                ->andReturn([
                    'summary' => 'ok',
                    'data_range' => null,
                ]);
        });

        $this->withToken('secret-token')
            ->getJson(route('rag.context'))
            ->assertOk()
            ->assertJsonPath('summary', 'ok');
    }
}
