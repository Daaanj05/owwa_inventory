<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic code generation
    |--------------------------------------------------------------------------
    */

    'auto_generate_item_codes' => env('INVENTORY_AUTO_ITEM_CODES', true),

    'auto_generate_property_numbers' => env('INVENTORY_AUTO_PROPERTY_NUMBERS', true),

    'require_serial_number_for_ppe' => env('INVENTORY_REQUIRE_SERIAL_PPE', true),

    /*
    |--------------------------------------------------------------------------
    | Reference series type per item category slug
    |--------------------------------------------------------------------------
    */

    'item_code_series' => [
        'consumables' => 'item_code_consumables',
        'ppe' => 'item_code_ppe',
        'semi_expendable' => 'item_code_semi',
    ],

    'property_number_series' => [
        'ppe' => 'property_number_ppe',
        'semi_expendable' => 'property_number_semi',
    ],

    /*
    |--------------------------------------------------------------------------
    | Semi-expendable COA value tiers (Circular 2022-004)
    |--------------------------------------------------------------------------
    */

    // SPLV: unit cost ≤ semi_low_value_max; SPHV: > semi_low_value_max and < semi_cap_threshold.
    'semi_low_value_max' => 5000,

    'semi_cap_threshold' => 50000,

    'semi_property_number' => [
        'pattern' => '{value_category}-{acq_year}-{supply_type_code}-{uacs_prefix}-{custodian_code}-{seq:3}',
    ],

    'semi_supply_type_codes' => [
        'ict' => 'ICT',
        'office_equipment' => 'OE',
        'furnitures_fixtures' => 'FF',
        'sports_equipment' => 'SE',
        'medical_equipment' => 'ME',
        'vehicle_equipment' => 'VE',
    ],

    'semi_uacs_prefixes' => [
        'ict' => '106',
        'office_equipment' => '106',
        'furnitures_fixtures' => '106',
        'sports_equipment' => '106',
        'medical_equipment' => '106',
        'vehicle_equipment' => '106',
    ],

    /*
    |--------------------------------------------------------------------------
    | Semi-expendable estimated useful life (COA Circular 2022-004)
    |--------------------------------------------------------------------------
    */

    'semi_min_useful_life_years' => 1,

    'semi_useful_life_defaults' => [
        'ict' => '5 yrs',
        'office_equipment' => '5 yrs',
        'furnitures_fixtures' => '5 yrs',
        'sports_equipment' => '5 yrs',
        'medical_equipment' => '5 yrs',
        'vehicle_equipment' => '5 yrs',
    ],

    'eul_nearing_days' => (int) env('INVENTORY_EUL_NEARING_DAYS', 90),

    'eul_warning_days' => (int) env('INVENTORY_EUL_WARNING_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Session audit & idle logout
    |--------------------------------------------------------------------------
    */

    'idle_logout_minutes' => (int) env('IDLE_LOGOUT_MINUTES', 30),

    'idle_warning_minutes' => (int) env('IDLE_WARNING_MINUTES', 5),

    'audit_log_archive_days' => (int) env('AUDIT_LOG_ARCHIVE_DAYS', 30),

    'password_reset_request_retention_days' => (int) env('PASSWORD_RESET_REQUEST_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | QR public asset lookup
    |--------------------------------------------------------------------------
    |
    | When enabled, QR labels encode a public URL (/assets/{propertyNumber})
    | that opens a read-only asset card in the phone browser.
    |
    */

    'qr_public_lookup' => env('INVENTORY_QR_PUBLIC_LOOKUP', true),

];
