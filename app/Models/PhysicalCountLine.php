<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalCountLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'physical_count_session_id',
        'item_id',
        'article',
        'description',
        'stock_number',
        'property_number',
        'unit_of_measure',
        'balance_per_card',
        'on_hand_count',
        'remarks',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhysicalCountSession::class, 'physical_count_session_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function shortageOverageQuantity(): int
    {
        return $this->on_hand_count - $this->balance_per_card;
    }
}
