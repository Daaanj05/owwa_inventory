<?php

namespace Tests\Unit;

use App\Models\DeliveryTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryTermTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_return_active_labels_only(): void
    {
        DeliveryTerm::query()->create(['label' => 'FOB Destination', 'is_active' => true]);
        DeliveryTerm::query()->create([
            'label' => 'Archived Term',
            'is_active' => false,
            'archived_at' => now(),
        ]);

        $options = DeliveryTerm::options();

        $this->assertSame(['FOB Destination' => 'FOB Destination'], $options);
    }

    public function test_remember_creates_active_term(): void
    {
        DeliveryTerm::remember('  Pick Up  ');
        DeliveryTerm::remember('Pick Up');
        DeliveryTerm::remember(' ');

        $this->assertDatabaseHas(DeliveryTerm::class, [
            'label' => 'Pick Up',
            'is_active' => true,
            'archived_at' => null,
        ]);
        $this->assertSame(1, DeliveryTerm::query()->count());
    }

    public function test_remember_restores_archived_term(): void
    {
        $term = DeliveryTerm::query()->create([
            'label' => 'FOB Origin',
            'is_active' => false,
            'archived_at' => now(),
        ]);

        DeliveryTerm::remember('FOB Origin');

        $term->refresh();
        $this->assertFalse($term->isArchived());
        $this->assertTrue($term->is_active);
        $this->assertArrayHasKey('FOB Origin', DeliveryTerm::options());
    }
}
