<?php

namespace Tests\Unit;

use App\Models\PhysicalCountSession;
use App\Support\OwwaReferenceLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OwwaReferenceLabelsTest extends TestCase
{
    public function test_issuance_control_labels_match_form_names(): void
    {
        $this->assertSame('Serial No.', OwwaReferenceLabels::issuanceControl('consumables'));
        $this->assertSame('PAR No.', OwwaReferenceLabels::issuanceControl('ppe'));
        $this->assertSame('ICS No.', OwwaReferenceLabels::issuanceControl('semi_expendable'));
    }

    public function test_disposal_labels_match_form_names(): void
    {
        $this->assertSame('WMR No.', OwwaReferenceLabels::disposal('consumables'));
        $this->assertSame('IIRUP No.', OwwaReferenceLabels::disposal('ppe'));
        $this->assertSame('IIRUSP No.', OwwaReferenceLabels::disposal('semi_expendable'));
    }

    public function test_internal_document_labels(): void
    {
        $this->assertSame('Request No.', OwwaReferenceLabels::propertyActionRequest());
        $this->assertSame('Tracking No.', OwwaReferenceLabels::openingBalanceTracking());
        $this->assertSame('Source document', OwwaReferenceLabels::openingBalanceSourceDocument());
        $this->assertSame('Plan No.', OwwaReferenceLabels::inventoryPlan());
        $this->assertSame('Case No.', OwwaReferenceLabels::acquisitionPaperwork());
        $this->assertSame('PTR No.', OwwaReferenceLabels::transfer());
        $this->assertSame('RIS No.', OwwaReferenceLabels::requisition());
        $this->assertSame('Transaction No.', OwwaReferenceLabels::employeeRequisitionTransaction());
        $this->assertSame('Reference', OwwaReferenceLabels::acquisition());
    }

    #[DataProvider('physicalCountLabelProvider')]
    public function test_physical_count_labels(string $countType, string $expected): void
    {
        $this->assertSame($expected, OwwaReferenceLabels::physicalCount($countType));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function physicalCountLabelProvider(): array
    {
        return [
            'rpci' => [PhysicalCountSession::TYPE_RPCI, 'RPCI No.'],
            'rpcppe' => [PhysicalCountSession::TYPE_RPCPPE, 'RPCPPE No.'],
            'rpcsp' => [PhysicalCountSession::TYPE_RPCSP, 'RPCSP No.'],
        ];
    }
}
