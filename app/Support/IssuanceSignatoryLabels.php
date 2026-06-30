<?php

namespace App\Support;

class IssuanceSignatoryLabels
{
    /**
     * @return array{custodian: string, custodian_designation: string, issued_to_designation: string, accounting_staff: string, section_description: string}
     */
    public static function forCategorySlug(?string $slug): array
    {
        return match ($slug) {
            'ppe' => [
                'custodian' => 'Issued by (PAR)',
                'custodian_designation' => 'Issued-by designation (PAR row 48)',
                'issued_to_designation' => 'Received-by designation (PAR row 48)',
                'accounting_staff' => 'Accounting staff',
                'section_description' => 'Printed names for Appendix 71 PAR signature block (rows 45–50).',
            ],
            'semi_expendable' => [
                'custodian' => 'Issued by / custodian (ICS)',
                'custodian_designation' => 'Custodian designation (ICS row 49)',
                'issued_to_designation' => 'Received-by designation (ICS row 49)',
                'accounting_staff' => 'Accounting staff',
                'section_description' => 'Printed names for Appendix 59 ICS signature block (rows 44–51).',
            ],
            default => [
                'custodian' => 'Supply officer / custodian (RSMI)',
                'custodian_designation' => 'Custodian designation',
                'issued_to_designation' => 'Recipient designation',
                'accounting_staff' => 'Accounting staff (RSMI row 52)',
                'section_description' => 'Printed names for Appendix 64 RSMI. Recipient name comes from the Issued to user.',
            ],
        };
    }
}
