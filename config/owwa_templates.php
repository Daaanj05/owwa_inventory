<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OWWA template filenames (keep original names from OWWA)
    |--------------------------------------------------------------------------
    */

    'requisition' => [
        'default' => [
            'file' => 'requisition/Appendix 63 - RIS.xls',
            'label' => 'Appendix 63 - RIS (Requisition and Issue Slip)',
        ],
    ],

    'issuance' => [
        'consumables' => [
            'default' => [
                'file' => 'Consumable/Issuances/Appendix 64 - RSMI.xls',
                'label' => 'Appendix 64 - RSMI (Report of Supplies and Materials Issued)',
            ],
        ],
        'ppe' => [
            'default' => [
                'file' => 'ppe/Issuances/Appendix 71 - PAR.xls',
                'label' => 'Appendix 71 - PAR (Property Acknowledgment Receipt)',
            ],
            'par' => [
                'file' => 'ppe/Issuances/Appendix 71 - PAR.xls',
                'label' => 'Appendix 71 - PAR',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file' => 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls',
                'label' => 'Appendix 59 - ICS (Inventory Custodian Slip)',
            ],
            'ics' => [
                'file' => 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls',
                'label' => 'Appendix 59 - ICS',
            ],
        ],
    ],

    'transfer' => [
        'ppe' => [
            'default' => [
                'file' => 'ppe/Transfer/Appendix 76 - PTR.xls',
                'label' => 'Appendix 76 - PTR (Property Transfer Report)',
            ],
            'ptr' => [
                'file' => 'ppe/Transfer/Appendix 76 - PTR.xls',
                'label' => 'Appendix 76 - PTR',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file' => 'Semi-Expendable/Transfer/Appendix 76 - PTR.xls',
                'label' => 'Appendix 76 - PTR (Property Transfer Report)',
            ],
            'ptr' => [
                'file' => 'Semi-Expendable/Transfer/Appendix 76 - PTR.xls',
                'label' => 'Appendix 76 - PTR',
            ],
        ],
    ],

    'disposal' => [
        'consumables' => [
            'default' => [
                'file' => 'Consumable/Disposal/Appendix 65 - WMR.xls',
                'label' => 'Appendix 65 - WMR (Waste Materials Report)',
            ],
            'wmr' => [
                'file' => 'Consumable/Disposal/Appendix 65 - WMR.xls',
                'label' => 'Appendix 65 - WMR',
            ],
        ],
        'ppe' => [
            'default' => [
                'file' => 'ppe/Disposal/Appendix 74 - IIRUP.xls',
                'label' => 'Appendix 74 - IIRUP (Unserviceable Property)',
            ],
            'iirup' => [
                'file' => 'ppe/Disposal/Appendix 74 - IIRUP.xls',
                'label' => 'Appendix 74 - IIRUP',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file' => 'Semi-Expendable/Disposal/Appendix 74 - IIRUP.xls',
                'label' => 'Appendix 74 - IIRUP (Unserviceable Property)',
            ],
            'iirup' => [
                'file' => 'Semi-Expendable/Disposal/Appendix 74 - IIRUP.xls',
                'label' => 'Appendix 74 - IIRUP',
            ],
        ],
    ],

    'incident_report' => [
        'default' => [
            'file' => 'Incident report/Appendix 75 - RLSDDP.xls',
            'label' => 'Appendix 75 - RLSDDP (Lost/Stolen/Damaged/Destroyed)',
        ],
        'rlsddp' => [
            'file' => 'Incident report/Appendix 75 - RLSDDP.xls',
            'label' => 'Appendix 75 - RLSDDP',
        ],
    ],

    'acquisition' => [
        'consumables' => [
            'default' => [
                'file' => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xls',
                'label' => 'Appendix 58 - Stock Card (receipt entry)',
            ],
        ],
        'ppe' => [
            'default' => [
                'file' => 'ppe/Accquisition/Appendix 69 - PC.xls',
                'label' => 'Appendix 69 - Property Card (receipt entry)',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file' => 'Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx',
                'label' => 'Annex A.1 - Semi-Expendable Property Card (receipt entry)',
            ],
        ],
    ],

    'property_class_sheets' => [
        'generic' => [
            'ict' => 'ICT',
            'office_equipment' => 'OFFICE EQUIPMENT',
            'furnitures_fixtures' => 'FURNITURES & FIXTURES',
            'sports_equipment' => 'SPORTS EQUIPMENT',
            'medical_equipment' => 'MEDICAL EQUIPMENT',
            'vehicle_equipment' => 'VEHICLE EQUIPMENT ',
        ],
        'forms' => [
            'annex_a1' => [
                'ict' => 'ICT',
                'office_equipment' => 'OFFICE EQUIPMENT',
                'furnitures_fixtures' => 'FURNITURES & FIXTURES',
                'sports_equipment' => 'SPORTS EQUIPMENT',
                'medical_equipment' => 'MEDICAL EQUIPMENT',
            ],
            'annex_a4' => [
                'ict' => 'ICT',
                'office_equipment' => 'OFFICE EQUIPMENT',
                'furnitures_fixtures' => 'F&F',
                'sports_equipment' => 'SPORTS EQUIPMENT',
                'medical_equipment' => 'MEDICAL EQUIPMENT',
                'vehicle_equipment' => 'VEHICLE EQUIPMENT ',
            ],
            'rpcsp' => [
                'ict' => 'ICT',
                'office_equipment' => 'OFFICE EQUIPMENT',
                'furnitures_fixtures' => 'FURNITURES & FIXTURES',
                'sports_equipment' => 'SPORTS EQUIPMENT',
                'medical_equipment' => 'MEDICAL EQUIPMENT',
            ],
        ],
        'default' => [
            'annex_a1' => 'OFFICE EQUIPMENT',
            'annex_a4' => 'OFFICE EQUIPMENT',
            'rpcsp' => 'OFFICE EQUIPMENT',
        ],
    ],

    'item_report' => [
        'consumables' => [
            'sc' => [
                'file' => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xls',
                'label' => 'Appendix 58 - Stock Card',
                'sheet_name' => 'SC',
            ],
        ],
        'ppe' => [
            'pc' => [
                'file' => 'ppe/Accquisition/Appendix 69 - PC.xls',
                'label' => 'Appendix 69 - Property Card',
            ],
        ],
        'semi_expendable' => [
            'annex_a1' => [
                'file' => 'Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx',
                'label' => 'Annex A.1 - Semi-Expendable Property Card',
            ],
            'annex_a4' => [
                'file' => 'Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xls',
                'label' => 'Annex A.4 - Registry of Semi-Expendable Property Issued',
            ],
        ],
    ],

    'physical_count' => [
        'consumables' => [
            'rpci' => [
                'file' => 'Consumable/Stock Levels & Recording/Appendix 66 - RPCI.xls',
                'label' => 'Appendix 66 - Report on Physical Count of Inventories',
            ],
        ],
        'ppe' => [
            'rpcppe' => [
                'file' => 'ppe/Recording (Stock Level)/Appendix 73 - RPCPPE.xls',
                'label' => 'Appendix 73 - Report on Physical Count of PPE',
            ],
        ],
        'semi_expendable' => [
            'rpcsp' => [
                'file' => 'Semi-Expendable/Recording (Stock Levels)/Inventory-Annex-A.8-RPCSP - REPORT.xlsx',
                'label' => 'Annex A.8 - Report on Physical Count of Semi-Expendable Property',
                'sheet_name' => 'RPCSP',
            ],
        ],
    ],

    'distribution' => [
        'consumables' => [
            'default' => [
                'file' => 'Consumable/Issuances/Appendix 64 - RSMI.xls',
                'label' => 'Appendix 64 - RSMI (distribution acknowledgment)',
            ],
        ],
        'ppe' => [
            'default' => [
                'file' => 'ppe/Issuances/Appendix 71 - PAR.xls',
                'label' => 'Appendix 71 - PAR (distribution acknowledgment)',
            ],
        ],
        'semi_expendable' => [
            'default' => [
                'file' => 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls',
                'label' => 'Appendix 59 - ICS (distribution acknowledgment)',
            ],
        ],
    ],

    'acquisition_paperwork' => [
        'consumables' => [
            'pr' => [
                'file' => 'Consumable/Acquisitions/Appendix 60 - PR.xls',
                'label' => 'Appendix 60 - Purchase Request',
            ],
            'po' => [
                'file' => 'Consumable/Acquisitions/Appendix 61 - PO.xls',
                'label' => 'Appendix 61 - Purchase Order',
            ],
            'iar' => [
                'file' => 'Consumable/Acquisitions/Appendix 62- IAR.xls',
                'label' => 'Appendix 62 - Inspection and Acceptance Report',
            ],
        ],
        'ppe' => [
            'pr' => [
                'file' => 'ppe/Accquisition/Appendix 60 - PR.xls',
                'label' => 'Appendix 60 - Purchase Request',
            ],
            'po' => [
                'file' => 'ppe/Accquisition/Appendix 61 - PO.xls',
                'label' => 'Appendix 61 - Purchase Order',
            ],
            'iar' => [
                'file' => 'ppe/Accquisition/Appendix 62- IAR.xls',
                'label' => 'Appendix 62 - Inspection and Acceptance Report',
            ],
        ],
        'semi_expendable' => [
            'pr' => [
                'file' => 'Semi-Expendable/Acquisition/Appendix 60 - PR.xls',
                'label' => 'Appendix 60 - Purchase Request',
            ],
            'po' => [
                'file' => 'Semi-Expendable/Acquisition/Appendix 61 - PO.xls',
                'label' => 'Appendix 61 - Purchase Order',
            ],
            'iar' => [
                'file' => 'Semi-Expendable/Acquisition/Appendix 62- IAR.xls',
                'label' => 'Appendix 62 - Inspection and Acceptance Report',
            ],
        ],
    ],

];
