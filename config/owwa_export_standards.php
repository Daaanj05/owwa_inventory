<?php

return [
    'currency' => [
        'excel_format_code' => '"P"#,##0.00',
    ],

    'ledger' => [
        'blank_rows_after_transactions' => 5,
        'font_name' => 'Times New Roman',
        'font_size' => 10,
        'row_height' => 15,
        'vertical_alignment' => 'center',
        'chars_per_line_width_factor' => 1.15,
        'chars_per_line_width_offset' => 0.5,
        'default_column_width' => 8.43,
        'max_wrap_lines' => 4,
        'column_types' => [
            'date' => 'center',
            'reference' => 'left',
            'text' => 'left',
            'qty' => 'center',
            'unit_cost' => 'center',
            'amount' => 'right',
            'balance' => 'right',
        ],
    ],
];
