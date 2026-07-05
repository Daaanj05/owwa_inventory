<?php

namespace App\Support;

use App\Models\PhysicalCountSession;
use Illuminate\Support\Collection;

class PhysicalCountPropertyClassResolver
{
    /**
     * @return Collection<int, string>
     */
    public static function classesForSession(PhysicalCountSession $session): Collection
    {
        $session->loadMissing('lines.item');

        return $session->lines
            ->map(fn ($line): string => ItemPropertyClass::resolveForExport($line->item?->property_class))
            ->unique()
            ->values();
    }

    public static function primaryClass(PhysicalCountSession $session): ?string
    {
        $classes = self::classesForSession($session);

        return $classes->count() === 1 ? (string) $classes->first() : null;
    }

    public static function inventoryTypeLabel(PhysicalCountSession $session): string
    {
        $primary = self::primaryClass($session);

        if ($primary !== null) {
            return ItemPropertyClass::propertyTypeLabel($primary);
        }

        if (self::classesForSession($session)->count() > 1) {
            return '';
        }

        return '';
    }

    public static function hasLineBasedClasses(PhysicalCountSession $session): bool
    {
        return self::classesForSession($session)->isNotEmpty();
    }

    public static function displayPropertyClassText(PhysicalCountSession $session): string
    {
        $classes = self::classesForSession($session);

        if ($classes->isEmpty()) {
            return 'Set after loading count lines';
        }

        if ($classes->count() > 1) {
            return 'Multiple property classes';
        }

        $class = (string) $classes->first();

        return ItemPropertyClass::options()[$class] ?? $class;
    }

    public static function displayInventoryTypeText(PhysicalCountSession $session): string
    {
        if ($session->count_type === PhysicalCountSession::TYPE_RPCI) {
            return (string) ($session->inventory_type_label ?? '');
        }

        $classes = self::classesForSession($session);

        if ($classes->isEmpty()) {
            return filled($session->inventory_type_label)
                ? (string) $session->inventory_type_label
                : 'Set after loading count lines';
        }

        if ($classes->count() > 1) {
            return 'Multiple property classes (one tab per class on export)';
        }

        return ItemPropertyClass::propertyTypeLabel((string) $classes->first());
    }

    public static function syncSession(PhysicalCountSession $session): PhysicalCountSession
    {
        if (! in_array($session->count_type, [PhysicalCountSession::TYPE_RPCSP, PhysicalCountSession::TYPE_RPCPPE], true)) {
            return $session;
        }

        $session->loadMissing('lines.item');
        $primary = self::primaryClass($session);

        if ($primary !== null) {
            $session->update([
                'property_class' => $session->count_type === PhysicalCountSession::TYPE_RPCSP ? $primary : $session->property_class,
                'inventory_type_label' => ItemPropertyClass::propertyTypeLabel($primary),
            ]);

            return $session->fresh();
        }

        if (self::classesForSession($session)->count() > 1) {
            $session->update([
                'property_class' => null,
                'inventory_type_label' => null,
            ]);

            return $session->fresh();
        }

        return $session;
    }
}
