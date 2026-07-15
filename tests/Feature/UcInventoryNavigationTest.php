<?php

namespace Tests\Feature;

use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Resources\Distributions\Pages\ListDistributions;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UcInventoryNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_uc_sidebar_includes_distributions_and_registry_without_category_links(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        $provider = new AdminPanelProvider($this->app);
        $method = new \ReflectionMethod($provider, 'getNavigationItems');
        $items = collect($method->invoke($provider));

        $labels = $items
            ->filter(fn (NavigationItem $item): bool => (bool) $item->isVisible())
            ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->values()
            ->all();

        $this->assertContains('Distributions', $labels);
        $this->assertContains('Office Property Registry', $labels);
        $this->assertContains('Employee Custody', $labels);
        $this->assertNotContains('Consumables', $labels);
        $this->assertNotContains('Semi-Expendable', $labels);

        $groups = $items
            ->filter(fn (NavigationItem $item): bool => (bool) $item->isVisible())
            ->map(fn (NavigationItem $item): string => (string) $item->getGroup())
            ->unique()
            ->values()
            ->all();

        $this->assertContains('Office', $groups);
        $this->assertNotContains('Inventory', $groups);
        $this->assertNotContains('Regional supply', $groups);
    }

    public function test_supply_custodian_still_sees_category_navigation_items(): void
    {
        ItemCategory::factory()->create(['name' => 'Consumables']);
        ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($custodian);

        $provider = new AdminPanelProvider($this->app);
        $method = new \ReflectionMethod($provider, 'getNavigationItems');
        $items = collect($method->invoke($provider));

        $labels = $items
            ->filter(fn (NavigationItem $item): bool => (bool) $item->isVisible())
            ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->values()
            ->all();

        $this->assertContains('Consumables', $labels);
        $this->assertContains('Semi-Expendable', $labels);
        $this->assertNotContains('Distributions', $labels);
        $this->assertNotContains('Office Property Registry', $labels);

        $groups = $items
            ->filter(fn (NavigationItem $item): bool => (bool) $item->isVisible())
            ->map(fn (NavigationItem $item): string => (string) $item->getGroup())
            ->unique()
            ->values()
            ->all();

        $this->assertContains('Regional supply', $groups);
        $this->assertNotContains('Inventory', $groups);
    }

    public function test_uc_can_open_distributions_without_category_dashboard_redirect(): void
    {
        $office = Office::factory()->create();
        ItemCategory::factory()->create(['name' => 'Consumables']);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc)
            ->get(DistributionResource::getUrl('index'))
            ->assertOk();

        Livewire::actingAs($uc)
            ->test(ListDistributions::class)
            ->assertOk()
            ->assertSee('Distributions');
    }
}
