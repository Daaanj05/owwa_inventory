<?php

/**
 * OWWA export cell references derived from storage/app/templates/*.xls analysis.
 * Regenerate template-structure.txt after replacing templates:
 *   php artisan owwa:analyze-templates
 */
return [

    'RIS' => [
        'template' => 'requisition/Appendix 63 - RIS.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'G6', 'label' => 'Fund Cluster : '],
            'division' => ['cell' => 'A8', 'label' => 'Division : '],
            'responsibility_center_code' => ['cell' => 'F8', 'label' => 'Responsibility Center Code : '],
            'office' => ['cell' => 'A9', 'label' => 'Office : '],
            'ris_no' => ['cell' => 'F9', 'label' => 'RIS No. : '],
            'purpose' => ['cell' => 'A32', 'label' => '   Purpose: '],
        ],
        'detail' => [
            'start_row' => 12,
            'max_rows' => 19,
            'columns' => [
                'stock_no' => 'A',
                'unit' => 'B',
                'description' => 'C',
                'quantity' => 'D',
                'stock_yes' => 'E',
                'stock_no_col' => 'F',
                'issue_quantity' => 'G',
                'issue_remarks' => 'H',
            ],
        ],
        'signatures' => [
            'requested_by' => 'B37',
            'approved_by' => 'D37',
        ],
    ],

    'RSMI' => [
        'template' => 'Consumable/Issuances/Appendix 64 - RSMI.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name: '],
            'serial_no' => ['cell' => 'G6', 'label' => 'Serial No.: '],
            'fund_cluster' => ['cell' => 'A7', 'label' => 'Fund Cluster: '],
            'date' => ['cell' => 'G7', 'label' => 'Date: '],
        ],
        'detail' => [
            'start_row' => 12,
            'end_row' => 32,
            'recap_start_row' => 36,
            'recap_end_row' => 51,
            'columns' => [
                'ris_no' => 'A',
                'responsibility_center' => 'B',
                'stock_no' => 'C',
                'item' => 'D',
                'unit' => 'E',
                'quantity' => 'F',
                'unit_cost' => 'G',
                'amount' => 'H',
            ],
        ],
    ],

    'PAR' => [
        'template' => 'ppe/Issuances/Appendix 71 - PAR.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'A7', 'label' => 'Fund Cluster:  '],
            'par_no' => ['cell' => 'E7', 'label' => 'PAR No.: '],
        ],
        'detail' => [
            'start_row' => 11,
            'columns' => [
                'quantity' => 'A',
                'unit' => 'B',
                'description' => 'C',
                'property_number' => 'D',
                'date_acquired' => 'E',
                'amount' => 'F',
            ],
        ],
    ],

    'ICS' => [
        'template' => 'Semi-Expendable/Issuances/Appendix 59 - ICS.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name: '],
            'fund_cluster' => ['cell' => 'A7', 'label' => 'Fund Cluster : '],
            'ics_no' => ['cell' => 'G7', 'label' => 'ICS No : '],
        ],
        'detail' => [
            'start_row' => 12,
            'columns' => [
                'quantity' => 'A',
                'unit' => 'B',
                'unit_cost' => 'C',
                'total_cost' => 'D',
                'description' => 'E',
                'inventory_item_no' => 'G',
                'useful_life' => 'H',
            ],
        ],
    ],

    'PTR' => [
        'template' => 'Appendix 76 - PTR.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'G6', 'label' => 'Fund Cluster : '],
            'from_accountable' => ['cell' => 'A8', 'label' => 'From Accountable Officer/Agency/Fund Cluster :  '],
            'ptr_no' => ['cell' => 'H8', 'label' => 'PTR No. :  '],
            'to_accountable' => ['cell' => 'A9', 'label' => 'To Accountable Officer/Agency/Fund Cluster : '],
            'date' => ['cell' => 'H9', 'label' => 'Date :  '],
        ],
        'detail' => [
            'start_row' => 17,
            'columns' => [
                'date_acquired' => 'A',
                'property_no' => 'B',
                'description' => 'D',
                'amount' => 'H',
                'condition' => 'I',
            ],
        ],
        'transfer_type_marks' => [
            'donation' => 'C13',
            'relocate' => 'F13',
            'reassignment' => 'C14',
            'others' => 'F14',
        ],
        'signatures' => [
            'reason' => 'A43',
            'approved_name' => 'B53',
            'released_name' => 'F53',
            'received_name' => 'H53',
            'approved_designation' => 'A54',
            'released_designation' => 'F54',
            'received_designation' => 'H54',
            'approved_date' => 'B55',
            'released_date' => 'F55',
            'received_date' => 'H55',
        ],
    ],

    'PC' => [
        'template' => 'ppe/Accquisition/Appendix 69 - PC.xls',
        'header' => [
            'entity_name' => ['cell' => 'B6', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'I6', 'label' => 'Fund Cluster: '],
            'property_number' => ['cell' => 'I9', 'label' => 'Property Number:'],
            'description' => ['cell' => 'B10', 'label' => 'Description : '],
        ],
        'ledger' => [
            'start_row' => 12,
            'max_rows' => 40,
            'columns' => [
                'date' => 'B',
                'reference' => 'C',
                'receipt_qty' => 'D',
                'issue_qty' => 'E',
                'office_officer' => 'F',
                'balance_qty' => 'H',
                'amount' => 'I',
                'remarks' => 'J',
            ],
        ],
    ],

    'ANNEX_A1' => [
        'template' => 'Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx',
        'template_sheet' => 'SPC',
        'block_stride' => 18,
        'header' => [
            'entity_name' => ['cell' => 'A8', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'K8', 'label' => 'Fund Cluster: '],
            'property_type' => ['cell' => 'A10', 'label' => 'Semi-expendable Property : '],
            'property_number' => ['cell' => 'K11', 'label' => 'Semi-expendable Property Number: '],
            'description' => ['cell' => 'A12', 'label' => 'Description : '],
        ],
        'ledger' => [
            'start_row' => 15,
            'max_rows' => 11,
            'clear_to_row' => 500,
            'columns' => [
                'date' => 'A',
                'reference' => 'B',
                'receipt_qty' => 'C',
                'unit_cost' => 'D',
                'total_cost' => 'E',
                'receipt_qty_dup' => 'F',
                'item_no' => 'G',
                'issue_qty' => 'H',
                'office_officer' => 'I',
                'balance_qty' => 'J',
                'amount' => 'K',
                'remarks' => 'L',
            ],
        ],
    ],

    'SC' => [
        'template' => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name: '],
            'fund_cluster' => ['cell' => 'F6', 'label' => 'Fund Cluster: '],
            'item' => ['cell' => 'A8', 'label' => 'Item : '],
            'stock_no' => ['cell' => 'F8', 'label' => 'Stock No. : '],
            'description' => ['cell' => 'A9', 'label' => 'Description : '],
            'reorder_point' => ['cell' => 'F9', 'label' => 'Re-order Point : '],
            'unit_of_measurement' => ['cell' => 'A10', 'label' => 'Unit of Measurement : '],
        ],
        'ledger' => [
            'start_row' => 13,
            'columns' => [
                'date' => 'A',
                'reference' => 'B',
                'receipt_qty' => 'C',
                'balance_qty' => 'F',
            ],
        ],
    ],

    'WMR' => [
        'template' => 'Consumable/Disposal/Appendix 65 - WMR.xls',
        'header' => [
            'entity_name' => ['cell' => 'A7', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'G7', 'label' => 'Fund Cluster : '],
            'place_of_storage' => ['cell' => 'A8', 'label' => 'Place of Storage : '],
            'date' => ['cell' => 'G8', 'label' => 'Date : '],
        ],
        'detail' => [
            'start_row' => 13,
            'columns' => [
                'item_no' => 'A',
                'quantity' => 'B',
                'unit' => 'C',
                'description' => 'D',
                'official_receipt_no' => 'G',
                'sale_date' => 'H',
                'sale_amount' => 'I',
            ],
        ],
        'disposal_mode_marks' => [
            'destroyed' => 'B32',
            'sold_private' => 'B33',
            'sold_public' => 'B34',
            'transferred_without_cost' => 'B35',
        ],
        'signatures' => [
            'prepared_by' => 'B25',
            'approved_by' => 'G25',
            'inspected_by' => 'B37',
            'witness' => 'G37',
        ],
    ],

    'RLSDDP' => [
        'template' => 'Appendix 75 - RLSDDP.xls',
        'header' => [
            'entity_name' => ['cell' => 'B6', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'G6', 'label' => 'Fund Cluster: '],
            'department_office' => ['cell' => 'B8', 'label' => 'Department/Office : '],
            'rlsddp_no' => ['cell' => 'G8', 'label' => 'RLSDDP No. : '],
            'accountable_officer' => ['cell' => 'B9', 'label' => 'Accountable Officer : '],
            'rlsddp_date' => ['cell' => 'G9', 'label' => 'RLSDDP Date : '],
            'designation' => ['cell' => 'B10', 'label' => 'Designation : '],
            'par_no' => ['cell' => 'G10', 'label' => 'PAR No. : '],
            'par_date' => ['cell' => 'G11', 'label' => 'PAR Date : '],
        ],
        'detail' => [
            'start_row' => 20,
            'columns' => [
                'property_no' => 'B',
                'description' => 'C',
                'acquisition_cost' => 'G',
            ],
        ],
        'extra' => [
            'circumstances' => 'B30',
        ],
        'police' => [
            'yes_mark' => 'C11',
            'station' => 'D11',
            'date' => 'D12',
            'no_mark' => 'C13',
        ],
        'property_status_marks' => [
            'lost' => 'D17',
            'stolen' => 'D18',
            'damaged' => 'F17',
            'destroyed' => 'F18',
        ],
        'gov_id' => [
            'type' => 'B44',
            'number' => 'B45',
            'date_issued' => 'B46',
        ],
        'signatures' => [
            'accountable_officer' => 'B39',
            'noted_by' => 'F39',
            'accountable_date' => 'B41',
            'noted_date' => 'F41',
        ],
    ],

    'IIRUP' => [
        'template' => 'ppe/Disposal/Appendix 74 - IIRUP.xls',
        'header' => [
            'entity_name' => ['cell' => 'B6', 'label' => 'Entity Name: '],
            'fund_cluster' => ['cell' => 'P6', 'label' => 'Fund Cluster: '],
            'accountable_officer' => ['cell' => 'B7', 'label' => ''],
            'accountable_designation' => ['cell' => 'F7', 'label' => ''],
            'accountable_station' => ['cell' => 'K7', 'label' => ''],
        ],
        'detail' => [
            'start_row' => 15,
            'columns' => [
                'date_acquired' => 'B',
                'description' => 'C',
                'property_no' => 'D',
                'quantity' => 'E',
                'remarks' => 'K',
            ],
        ],
        'disposal_mode_columns' => [
            'sale' => 'L',
            'transfer' => 'M',
            'destruction' => 'N',
            'others' => 'O',
        ],
        'signatures' => [
            'custodian' => 'C40',
            'approved_by' => 'H40',
            'inspection_officer' => 'L40',
            'witness' => 'Q40',
        ],
    ],

    'IIRUSP' => [
        'template' => 'ppe/Disposal/Appendix 74 - IIRUP.xls',
        'header' => [
            'entity_name' => ['cell' => 'B9', 'label' => 'Entity Name: '],
            'fund_cluster' => ['cell' => 'P9', 'label' => 'Fund Cluster: '],
            'accountable_officer' => ['cell' => 'C10', 'label' => ''],
            'accountable_designation' => ['cell' => 'F10', 'label' => ''],
            'accountable_station' => ['cell' => 'K10', 'label' => ''],
        ],
        'detail' => [
            'start_row' => 18,
            'columns' => [
                'date_acquired' => 'B',
                'description' => 'C',
                'property_no' => 'D',
                'quantity' => 'E',
                'remarks' => 'K',
            ],
        ],
        'disposal_mode_columns' => [
            'sale' => 'L',
            'transfer' => 'M',
            'destruction' => 'N',
            'others' => 'O',
        ],
        'signatures' => [
            'custodian' => 'C37',
            'approved_by' => 'H37',
            'inspection_officer' => 'L38',
            'witness' => 'Q38',
        ],
    ],

    'RPCI' => [
        'template' => 'Consumable/Stock Levels & Recording/Appendix 66 - RPCI.xls',
        'signatures' => [
            'certified_by' => 'B35',
            'approved_by' => 'F35',
            'verified_by' => 'B37',
        ],
    ],

    'RPCPPE' => [
        'template' => 'ppe/Recording (Stock Level)/Appendix 73 - RPCPPE.xls',
        'signatures' => [
            'certified_by' => 'C35',
            'approved_by' => 'G35',
            'verified_by' => 'C37',
        ],
    ],

    'RPCSP' => [
        'template' => 'Semi-Expendable/Recording (Stock Levels)/Inventory-Annex-A.8-RPCSP - REPORT.xlsx',
        'signatures' => [
            'certified_by' => 'B35',
            'approved_by' => 'F35',
            'verified_by' => 'B37',
        ],
    ],

    'PR' => [
        'template' => 'Consumable/Acquisitions/Appendix 60 - PR.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name: '],
            'fund_cluster' => ['cell' => 'D6', 'label' => 'Fund Cluster: '],
            'office_section' => ['cell' => 'A7', 'label' => 'Office/Section : '],
            'pr_no' => ['cell' => 'C7', 'label' => 'PR No.: '],
            'date' => ['cell' => 'E7', 'label' => 'Date: '],
            'responsibility_center_code' => ['cell' => 'C8', 'label' => 'Responsibility Center Code : '],
            'purpose' => ['cell' => 'A33', 'label' => 'Purpose: '],
        ],
        'detail' => [
            'start_row' => 11,
            'max_rows' => 22,
            'columns' => [
                'stock_no' => 'A',
                'unit' => 'B',
                'description' => 'C',
                'quantity' => 'D',
                'unit_cost' => 'E',
                'total_cost' => 'F',
            ],
        ],
        'signatures' => [
            'requested_by' => 'B39',
            'approved_by' => 'D39',
        ],
    ],

    'PO' => [
        'template' => 'Consumable/Acquisitions/Appendix 61 - PO.xls',
        'header' => [
            'supplier' => ['cell' => 'A7', 'label' => 'Supplier : '],
            'po_no' => ['cell' => 'D7', 'label' => 'P.O. No. : '],
            'date' => ['cell' => 'D8', 'label' => 'Date : '],
            'address' => ['cell' => 'A8', 'label' => 'Address : '],
            'tin' => ['cell' => 'A9', 'label' => 'TIN : '],
            'mode_of_procurement' => ['cell' => 'D9', 'label' => 'Mode of Procurement : '],
            'place_of_delivery' => ['cell' => 'A13', 'label' => 'Place of Delivery :  '],
            'delivery_term' => ['cell' => 'D13', 'label' => 'Delivery Term : '],
            'date_of_delivery' => ['cell' => 'A14', 'label' => 'Date of Delivery : '],
            'payment_term' => ['cell' => 'D14', 'label' => 'Payment Term : '],
            'fund_cluster' => ['cell' => 'A45', 'label' => 'Fund Cluster : '],
        ],
        'detail' => [
            'start_row' => 16,
            'max_rows' => 16,
            'columns' => [
                'stock_no' => 'A',
                'unit' => 'B',
                'description' => 'C',
                'quantity' => 'D',
                'unit_cost' => 'E',
                'amount' => 'F',
            ],
        ],
        'signatures' => [
            'supplier_signatory' => 'A39',
            'authorized_official' => 'D39',
        ],
    ],

    'IAR' => [
        'template' => 'Consumable/Acquisitions/Appendix 62- IAR.xls',
        'header' => [
            'entity_name' => ['cell' => 'A6', 'label' => 'Entity Name : '],
            'fund_cluster' => ['cell' => 'D6', 'label' => 'Fund Cluster : '],
            'supplier' => ['cell' => 'A8', 'label' => ' Supplier : '],
            'iar_no' => ['cell' => 'D8', 'label' => 'IAR No. : '],
            'po_no_date' => ['cell' => 'A9', 'label' => ' PO No./Date : '],
            'date' => ['cell' => 'D9', 'label' => 'Date : '],
            'requisitioning_office' => ['cell' => 'A10', 'label' => ' Requisitioning Office/Dept. : '],
            'invoice_no' => ['cell' => 'D10', 'label' => 'Invoice No. : '],
            'responsibility_center_code' => ['cell' => 'A11', 'label' => ' Responsibility Center Code : '],
            'invoice_date' => ['cell' => 'D11', 'label' => 'Date : '],
            'date_inspected' => ['cell' => 'A28', 'label' => 'Date Inspected : '],
            'date_received' => ['cell' => 'C28', 'label' => 'Date Received : '],
        ],
        'detail' => [
            'start_row' => 14,
            'max_rows' => 13,
            'columns' => [
                'stock_no' => 'A',
                'description' => 'B',
                'unit' => 'D',
                'quantity' => 'E',
            ],
        ],
        'signatures' => [
            'inspection_officer' => 'A35',
            'supply_custodian' => 'C35',
        ],
    ],

];
