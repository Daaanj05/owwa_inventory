<?php

namespace Tests\Unit;

use App\Support\SignatorySelect;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignatorySelectInstructionTest extends TestCase
{
    #[Test]
    public function every_role_option_has_a_non_empty_instruction(): void
    {
        foreach (array_keys(SignatorySelect::roleOptions()) as $role) {
            $instruction = SignatorySelect::roleInstruction($role);

            $this->assertNotNull($instruction, "Missing instruction for role [{$role}]");
            $this->assertNotSame('', trim($instruction), "Empty instruction for role [{$role}]");
        }
    }

    #[Test]
    public function disposal_instruction_keys_return_copy(): void
    {
        $expected = [
            'custodian_consumables' => 'Name of the Supply / Property Custodian.',
            'custodian_iirup' => 'Name of the Accountable Officer.',
            'approved_consumables' => 'Name of the Head / Authorized Representative.',
            'approved_iirup' => 'Name of the Head / Authorized Representative.',
            'inspection_officer' => 'Name of the inspection officer on the disposal report.',
            'witness' => 'Name of the person who witnessed the disposal.',
        ];

        foreach ($expected as $key => $copy) {
            $instruction = SignatorySelect::disposalInstruction($key);

            $this->assertSame($copy, $instruction, "Unexpected disposal instruction [{$key}]");
            $this->assertStringNotContainsStringIgnoringCase('signature', $instruction);
        }

        $this->assertNull(SignatorySelect::disposalInstruction('unknown'));
    }

    #[Test]
    public function disposal_witness_role_instruction_matches_name_of_style(): void
    {
        $this->assertSame(
            'Name of the person who witnessed the disposal.',
            SignatorySelect::roleInstruction(\App\Models\ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS),
        );
    }

    #[Test]
    public function signatory_role_labels_name_the_person(): void
    {
        $labels = SignatorySelect::roleOptions();

        $this->assertArrayNotHasKey(\App\Models\ProcurementSignatoryName::ROLE_REQUESTED, $labels);
        $this->assertSame('Approver', $labels[\App\Models\ProcurementSignatoryName::ROLE_APPROVED]);
        $this->assertSame('RIS approver', $labels[\App\Models\ProcurementSignatoryName::ROLE_RIS_APPROVED]);
        $this->assertSame('Transfer approver', $labels[\App\Models\ProcurementSignatoryName::ROLE_TRANSFER_APPROVED]);
        $this->assertSame('requisition', \App\Support\SignatorySelect::tabForRole(\App\Models\ProcurementSignatoryName::ROLE_RIS_APPROVED));
        $this->assertSame('Transfer releasing officer', $labels[\App\Models\ProcurementSignatoryName::ROLE_TRANSFER_RELEASED]);
        $this->assertSame('Transfer receiving officer', $labels[\App\Models\ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED]);
        $this->assertSame('Certifying officer', $labels[\App\Models\ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED]);
        $this->assertSame('Approving officer', $labels[\App\Models\ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED]);
        $this->assertSame('COA representative', $labels[\App\Models\ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED]);
    }

    #[Test]
    public function pr_name_roles_use_printed_name_helper_copy(): void
    {
        $this->assertSame(
            'Name of the person requesting the purchase of the item/s.',
            SignatorySelect::roleInstruction(\App\Models\ProcurementSignatoryName::ROLE_REQUESTED),
        );
        $this->assertSame(
            'Name of the person approving the purchase of the item/s.',
            SignatorySelect::roleInstruction(\App\Models\ProcurementSignatoryName::ROLE_APPROVED),
        );
    }
}
