<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OWWA template filenames (keep original names from OWWA)
    |--------------------------------------------------------------------------
    |
    | Map transaction type + category + form to the actual .xlsx/.xls filename.
    | Place files in storage/app/templates/{category}/ (consumables/, ppe/, semi_expendable/).
    | Use .xlsx in config; the export service will try .xls if .xlsx is missing.
    |
    | Structure: [ 'issuance' => [ 'consumables' => [ 'default' => [...], 'rsmi' => [...] ], ... ], ... ]
    | Each form entry: 'file' => 'Appendix 64 - RSMI.xlsx', 'label' => 'Appendix 64 - RSMI' (optional)
    |
    */

    'issuance' => [
        'consumables' => [
            'default' => [
                'file'  => 'Appendix 64 - RSMI.xlsx',
                'label' => 'Appendix 64 - RSMI (Report of Supplies and Materials Issued)',
            ],
        ],
        'ppe' => [
            'default' => [
                'file'  => 'Appendix 71 - PAR.xlsx',
                'label' => 'Appendix 71 - PAR (Property Acknowledgment Receipt)',
            ],
            'par' => [
                'file'  => 'Appendix 71 - PAR.xlsx',
                'label' => 'Appendix 71 - PAR',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file'  => 'Appendix 59 - ICS.xlsx',
                'label' => 'Appendix 59 - ICS (Inventory Custodian Slip)',
            ],
            'ics' => [
                'file'  => 'Appendix 59 - ICS.xlsx',
                'label' => 'Appendix 59 - ICS',
            ],
        ],
    ],

    'transfer' => [
        'consumables' => [
            'default' => [
                'file'  => 'Appendix 64 - RSMI.xlsx',
                'label' => 'Appendix 64 - RSMI (transfer stand-in)',
            ],
        ],
        'ppe' => [
            'default' => [
                'file'  => 'Appendix 76 - PTR.xlsx',
                'label' => 'Appendix 76 - PTR (Property Transfer Report)',
            ],
            'ptr' => [
                'file'  => 'Appendix 76 - PTR.xlsx',
                'label' => 'Appendix 76 - PTR',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file'  => 'Appendix 76 - PTR.xlsx',
                'label' => 'Appendix 76 - PTR (Property Transfer Report)',
            ],
            'ptr' => [
                'file'  => 'Appendix 76 - PTR.xlsx',
                'label' => 'Appendix 76 - PTR',
            ],
        ],
    ],

    'disposal' => [
        'consumables' => [
            'default' => [
                'file'  => 'Appendix 65 - WMR.xlsx',
                'label' => 'Appendix 65 - WMR (Waste Materials Report)',
            ],
            'wmr' => [
                'file'  => 'Appendix 65 - WMR.xlsx',
                'label' => 'Appendix 65 - WMR',
            ],
        ],
        'ppe' => [
            'default' => [
                'file'  => 'Appendix 74 - IIRUP.xlsx',
                'label' => 'Appendix 74 - IIRUP (Unserviceable Property)',
            ],
            'iirup' => [
                'file'  => 'Appendix 74 - IIRUP.xlsx',
                'label' => 'Appendix 74 - IIRUP',
            ],
            'rlsddp' => [
                'file'  => 'Appendix 75 - RLSDDP.xlsx',
                'label' => 'Appendix 75 - RLSDDP (Lost/Stolen/Damaged/Destroyed)',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file'  => 'Annex A.10- IIRUSP.xlsx',
                'label' => 'Annex A.10 - IIRUSP (Unserviceable Semi-Expendable)',
            ],
            'iirusp' => [
                'file'  => 'Annex A.10- IIRUSP.xlsx',
                'label' => 'Annex A.10 - IIRUSP',
            ],
            'rlsddp' => [
                'file'  => 'Appendix 75 - RLSDDP.xlsx',
                'label' => 'Appendix 75 - RLSDDP (Lost/Stolen/Damaged/Destroyed)',
            ],
        ],
    ],

];
