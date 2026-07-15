<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic code generation
    |--------------------------------------------------------------------------
    */

    'auto_generate_item_codes' => env('INVENTORY_AUTO_ITEM_CODES', true),

    'auto_generate_property_numbers' => env('INVENTORY_AUTO_PROPERTY_NUMBERS', true),

    'require_serial_number_for_ppe' => env('INVENTORY_REQUIRE_SERIAL_PPE', false),

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
        'pattern' => '{value_category}-{acq_year}-{class_code}-{uacs_code}-{seq:3}-{location}',
        'provisional_prefix' => 'TEMP',
    ],

    'ppe_property_number' => [
        'pattern' => '{acq_year}-{class_code}-{uacs_code}-{seq:3}-{location}',
    ],

    'catalog_class_codes' => [
        'information_technology' => 'IT',
        'furniture_fixtures' => 'FF',
        'office_equipment' => 'OE',
        'communication_equipment' => 'CE',
        'appliances' => 'AP',
        'machinery_equipment' => 'ME',
        'transportation_equipment' => 'TE',
        'medical_equipment' => 'MD',
    ],

    // Legacy key aliases — prefer catalog_class_codes + UacsObjectCode.
    'semi_supply_type_codes' => [
        'information_technology' => 'IT',
        'furniture_fixtures' => 'FF',
        'office_equipment' => 'OE',
        'communication_equipment' => 'CE',
        'appliances' => 'AP',
        'machinery_equipment' => 'ME',
        'transportation_equipment' => 'TE',
        'medical_equipment' => 'MD',
        // legacy
        'ict' => 'IT',
        'furnitures_fixtures' => 'FF',
        'sports_equipment' => 'ME',
        'vehicle_equipment' => 'TE',
    ],

    'semi_uacs_prefixes' => [
        'information_technology' => '106',
        'furniture_fixtures' => '106',
        'office_equipment' => '106',
        'communication_equipment' => '106',
        'appliances' => '106',
        'machinery_equipment' => '106',
        'transportation_equipment' => '106',
        'medical_equipment' => '106',
    ],

    /*
    |--------------------------------------------------------------------------
    | Semi-expendable estimated useful life (COA Circular 2022-004)
    |--------------------------------------------------------------------------
    */

    'semi_min_useful_life_years' => 1,

    'semi_useful_life_defaults' => [
        'information_technology' => '5 yrs',
        'furniture_fixtures' => '5 yrs',
        'office_equipment' => '5 yrs',
        'communication_equipment' => '5 yrs',
        'appliances' => '5 yrs',
        'machinery_equipment' => '5 yrs',
        'transportation_equipment' => '5 yrs',
        'medical_equipment' => '5 yrs',
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
    */

    'qr_public_lookup' => env('INVENTORY_QR_PUBLIC_LOOKUP', true),

    'requisition_poll_interval' => env('REQUISITION_POLL_INTERVAL', '60s'),

];
