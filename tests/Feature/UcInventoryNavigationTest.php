<?php

namespace Tests\Feature;

use App\Filament\Pages\MyInventory;
use App\Filament\Pages\RegionalSupplyCatalog;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertNotContains('Distributions', $labels);
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

        $this->assertNotContains('Office', $groups);
        $this->assertNotContains('Inventory', $groups);
        $this->assertNotContains('Regional supply', $groups);
    }

    public function test_employee_and_uc_requisition_nav_items_have_no_group(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->actingAs($employee);

        $this->assertNull(RequisitionResource::getNavigationGroup());
        $this->assertNull(PropertyActionRequestResource::getNavigationGroup());
        $this->assertNull(MyInventory::getNavigationGroup());
        $this->assertNull(RegionalSupplyCatalog::getNavigationGroup());

        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $this->actingAs($uc);

        $this->assertNull(RequisitionResource::getNavigationGroup());
        $this->assertNull(PropertyActionRequestResource::getNavigationGroup());
        $this->assertNull(RegionalSupplyCatalog::getNavigationGroup());
    }

    public function test_supply_custodian_keeps_requisitions_navigation_group(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $this->assertSame('Requisitions', RequisitionResource::getNavigationGroup());
        $this->assertSame('Requisitions', PropertyActionRequestResource::getNavigationGroup());
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

        $this->assertContains('Stock levels', $labels);
        $this->assertContains('Items', $labels);
        $this->assertNotContains('Consumables', $labels);
        $this->assertNotContains('Semi-Expendable', $labels);
        $this->assertNotContains('Distributions', $labels);
        $this->assertNotContains('Office Property Registry', $labels);

        $visible = $items->filter(fn (NavigationItem $item): bool => (bool) $item->isVisible());

        $groups = $visible
            ->map(fn (NavigationItem $item): string => (string) $item->getGroup())
            ->unique()
            ->values()
            ->all();

        $this->assertContains('Consumables', $groups);
        $this->assertContains('Semi-Expendable', $groups);
        $this->assertNotContains('Regional supply', $groups);
        $this->assertNotContains('Inventory', $groups);

        $consumableLabels = $visible
            ->filter(fn (NavigationItem $item): bool => $item->getGroup() === 'Consumables')
            ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->all();
        $semiLabels = $visible
            ->filter(fn (NavigationItem $item): bool => $item->getGroup() === 'Semi-Expendable')
            ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
            ->all();

        $this->assertNotContains('Transfers', $consumableLabels);
        $this->assertContains('Transfers', $semiLabels);

        $groupsMethod = new \ReflectionMethod($provider, 'supplyNavigationGroups');
        $groupLabels = collect($groupsMethod->invoke($provider))
            ->map(fn ($group): string => (string) $group->getLabel())
            ->values()
            ->all();
        $analyticsIndex = array_search('Analytics', $groupLabels, true);
        $supplyLinksIndex = array_search('Supply links', $groupLabels, true);
        $setupIndex = array_search('Setup', $groupLabels, true);

        $this->assertIsInt($analyticsIndex);
        $this->assertIsInt($supplyLinksIndex);
        $this->assertIsInt($setupIndex);
        $this->assertLessThan($supplyLinksIndex, $analyticsIndex);
        $this->assertLessThan($setupIndex, $supplyLinksIndex);
    }

    public function test_distributions_module_is_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('Filament/Resources/Distributions/DistributionResource.php'));
        $this->assertFileDoesNotExist(app_path('Services/DistributionCompileService.php'));
    }
}
