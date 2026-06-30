<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferItemOptionsService;
use App\Services\TransferStockValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferStockValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_quantity_greater_than_stock_on_create(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->createAcquisition($item->id, $office->id, 5);

        $this->expectException(ValidationException::class);

        app(TransferStockValidator::class)->validateForCreate([
            'from_office_id' => $office->id,
            'to_office_id' => $other->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ], $user);
    }

    public function test_rejects_same_from_and_to_office(): void
    {
        $office = Office::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->createAcquisition($item->id, $office->id, 5);

        try {
            app(TransferStockValidator::class)->validateForCreate([
                'from_office_id' => $office->id,
                'to_office_id' => $office->id,
                'item_id' => $item->id,
                'quantity' => 1,
            ], $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to_office_id', $e->errors());
        }
    }

    public function test_allows_transfer_from_satellite_office_when_stock_exists(): void
    {
        $home = Office::factory()->create();
        $satellite = Office::factory()->create();
        $destination = Office::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        $this->createAcquisition($item->id, $satellite->id, 5);

        app(TransferStockValidator::class)->validateForCreate([
            'from_office_id' => $satellite->id,
            'to_office_id' => $destination->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ], $user);

        $this->assertTrue(true);
    }

    public function test_allows_edit_quantity_within_stock_plus_existing_quantity(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->createAcquisition($item->id, $office->id, 10);

        $transfer = Transfer::query()->create([
            'reference_code' => 'TR-TEST-1',
            'item_id' => $item->id,
            'from_office_id' => $office->id,
            'to_office_id' => $other->id,
            'quantity' => 4,
            'transfer_date' => now()->toDateString(),
        ]);

        app(TransferStockValidator::class)->validateForUpdate([
            'from_office_id' => $office->id,
            'to_office_id' => $other->id,
            'item_id' => $item->id,
            'quantity' => 8,
        ], $transfer, $user);

        $this->assertTrue(true);
    }

    public function test_blocks_quantity_when_stock_is_zero_on_create(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->createAcquisition($item->id, $office->id, 5);
        $this->createIssuance($item->id, $office->id, 5);

        $options = app(TransferItemOptionsService::class)->optionsForFromOffice($office->id, $category->id);
        $this->assertArrayHasKey($item->id, $options);

        try {
            app(TransferStockValidator::class)->validateForCreate([
                'from_office_id' => $office->id,
                'to_office_id' => $other->id,
                'item_id' => $item->id,
                'quantity' => 1,
            ], $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
    }

    protected function createAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TEST-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createIssuance(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('issuances')->insert([
            'reference_code' => 'ISS-TEST-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'issuance_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
