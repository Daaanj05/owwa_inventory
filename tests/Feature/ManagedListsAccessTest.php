<?php

namespace Tests\Feature;

use App\Filament\Resources\DeliveryTerms\DeliveryTermResource;
use App\Filament\Resources\ItemAttributeOptions\ItemAttributeOptionResource;
use App\Filament\Resources\ProcurementSignatoryNames\ProcurementSignatoryNameResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedListsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_custodian_can_view_setup_list_resources(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);
        Filament::auth()->login($custodian);

        $this->assertTrue(ProcurementSignatoryNameResource::canViewAny());
        $this->assertTrue(SupplierResource::canViewAny());
        $this->assertTrue(DeliveryTermResource::canViewAny());
        $this->assertFalse(ItemAttributeOptionResource::canViewAny());
    }

    public function test_system_admin_can_view_item_attribute_lists_only(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);
        $this->actingAs($admin);
        Filament::auth()->login($admin);

        $this->assertFalse(ProcurementSignatoryNameResource::canViewAny());
        $this->assertFalse(SupplierResource::canViewAny());
        $this->assertFalse(DeliveryTermResource::canViewAny());
        $this->assertTrue(ItemAttributeOptionResource::canViewAny());
    }

    public function test_employee_cannot_view_managed_list_resources(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->actingAs($employee);
        Filament::auth()->login($employee);

        $this->assertFalse(ProcurementSignatoryNameResource::canViewAny());
        $this->assertFalse(SupplierResource::canViewAny());
        $this->assertFalse(DeliveryTermResource::canViewAny());
        $this->assertFalse(ItemAttributeOptionResource::canViewAny());
    }
}
