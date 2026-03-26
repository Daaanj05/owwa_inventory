<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    use HasFactory;

    public const POWER_PLANT_EQUIPMENT = 'power_plant_equipment';
    public const SEMI_EXPENDABLE = 'semi_expendable';
    public const CONSUMABLES = 'consumables';

    protected $fillable = ['name', 'description'];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'item_category_id');
    }

    /**
     * Template filename slug for OWWA forms per category (consumables.xlsx, ppe.xlsx, semi_expendable.xlsx).
     */
    public function getTemplateSlug(): string
    {
        $name = strtolower(trim((string) $this->name));
        if (in_array($name, ['consumables', 'consumable'], true)) {
            return 'consumables';
        }
        if (in_array($name, ['ppe', 'power plant equipment', 'power_plant_equipment'], true)) {
            return 'ppe';
        }
        if (in_array($name, ['semi-expendable', 'semi expendable', 'semi_expendable'], true)) {
            return 'semi_expendable';
        }

        return 'consumables';
    }
}
