<?php

namespace App\Support;

class ModeOfProcurementOptions
{
    /**
     * Official modes under RA No. 9184 (Competitive Bidding + Alternative Methods).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $values = [
            'Public Bidding',
            'Limited Source Bidding',
            'Direct Contracting',
            'Repeat Order',
            'Shopping',
            'Negotiated Procurement',
        ];

        return array_combine($values, $values);
    }

    /**
     * @return list<string>
     */
    public static function deliveryTermSuggestions(): array
    {
        return [
            'FOB Destination',
            'FOB Shipping Point',
        ];
    }
}
