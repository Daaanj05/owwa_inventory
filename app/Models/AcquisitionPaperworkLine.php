<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcquisitionPaperworkLine extends Model
{
    protected $table = 'acquisition_paperwork_lines';

    protected $fillable = [
        'acquisition_paperwork_id',
        'item_id',
        'description',
        'unit',
        'quantity',
        'unit_cost',
        'amount',
        'line_remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AcquisitionPaperworkLine $line): void {
            if ($line->unit_cost !== null && $line->quantity > 0) {
                $line->amount = round((float) $line->unit_cost * $line->quantity, 2);
            }
        });
    }

    public function acquisitionPaperwork(): BelongsTo
    {
        return $this->belongsTo(AcquisitionPaperwork::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockNumber(): string
    {
        return (string) ($this->item?->item_code ?? '');
    }
}
