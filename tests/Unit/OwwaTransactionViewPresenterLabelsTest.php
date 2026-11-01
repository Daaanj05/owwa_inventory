<?php

namespace Tests\Unit;

use App\Models\Disposal;
use App\Models\DisposalBatch;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Support\OwwaTransactionViewPresenter;
use Tests\TestCase;

class OwwaTransactionViewPresenterLabelsTest extends TestCase
{
    public function test_consumable_issuance_hero_uses_serial_no_and_ris_no(): void
    {
        $category = new ItemCategory(['name' => 'Consumables']);
        $item = new Item(['name' => 'Bond Paper']);
        $item->setRelation('category', $category);
        $office = new Office(['name' => 'Regional Welfare Office']);
        $requisition = new Requisition(['reference_code' => '2026-09-0201']);
        $issuance = new Issuance([
            'reference_code' => '2026-09-0026',
            'quantity' => 1,
            'issuance_date' => '2026-09-30',
        ]);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', null);
        $issuance->setRelation('requisition', $requisition);
        $issuance->setRelation('batch', null);
        $issuance->setRelation('issuedTo', null);

        $hero = OwwaTransactionViewPresenter::forIssuance($issuance);

        $this->assertSame('Serial No.', $hero['referenceLabel']);
        $this->assertSame('2026-09-0026', $hero['reference']);
        $this->assertSame(
            '2026-09-0201',
            collect($hero['meta'])->firstWhere('label', 'RIS No.')['value'] ?? null,
        );
    }

    public function test_ppe_issuance_hero_uses_par_no(): void
    {
        $category = new ItemCategory(['name' => 'PPE']);
        $item = new Item(['name' => 'Laptop']);
        $item->setRelation('category', $category);
        $issuance = new Issuance([
            'reference_code' => '2026-07-0001',
            'quantity' => 1,
            'issuance_date' => '2026-07-01',
        ]);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('office', new Office(['name' => 'Regional Welfare Office']));
        $issuance->setRelation('department', null);
        $issuance->setRelation('requisition', null);
        $issuance->setRelation('batch', null);
        $issuance->setRelation('issuedTo', null);

        $hero = OwwaTransactionViewPresenter::forIssuance($issuance);

        $this->assertSame('PAR No.', $hero['referenceLabel']);
    }

    public function test_transfer_hero_uses_ptr_no(): void
    {
        $transfer = new Transfer([
            'reference_code' => '2026-08-0007',
            'quantity' => 1,
            'transfer_date' => '2026-08-01',
        ]);
        $transfer->setRelation('item', new Item(['name' => 'Chair']));
        $transfer->setRelation('fromOffice', new Office(['name' => 'From']));
        $transfer->setRelation('toOffice', new Office(['name' => 'To']));

        $hero = OwwaTransactionViewPresenter::forTransfer($transfer);

        $this->assertSame('PTR No.', $hero['referenceLabel']);
        $this->assertSame('2026-08-0007', $hero['reference']);
    }

    public function test_disposal_hero_uses_category_form_label(): void
    {
        $category = new ItemCategory(['name' => 'Consumables']);
        $item = new Item(['name' => 'Bond Paper']);
        $item->setRelation('category', $category);
        $disposal = new Disposal([
            'reference_code' => '2026-03-0001',
            'quantity' => 1,
            'disposal_date' => '2026-03-01',
            'disposal_type' => 'waste_sale',
        ]);
        $disposal->setRelation('item', $item);
        $disposal->setRelation('office', new Office(['name' => 'Regional Welfare Office']));
        $disposal->setRelation('batch', new DisposalBatch(['category_slug' => 'consumables']));

        $hero = OwwaTransactionViewPresenter::forDisposal($disposal);

        $this->assertSame('WMR No.', $hero['referenceLabel']);
        $this->assertSame('2026-03-0001', $hero['reference']);
    }
}
