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
            'inspection_officer' => 'Name of the Inspection Officer.',
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
