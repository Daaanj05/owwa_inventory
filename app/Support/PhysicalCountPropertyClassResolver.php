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

        if ($session->count_type === PhysicalCountSession::TYPE_RPCI) {
            return $session->lines
                ->map(fn ($line): ?string => ConsumableInventoryType::normalize($line->item?->inventory_type))
                ->filter()
                ->unique()
                ->values();
        }

        return $session->lines
            ->map(function ($line) use ($session): string {
                if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE) {
                    return PpePropertyType::resolveForExport($line->item?->ppe_type);
                }

                return ItemPropertyClass::resolveForExport($line->item?->property_class);
            })
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
        if ($session->count_type === PhysicalCountSession::TYPE_RPCI) {
            $primary = self::primaryClass($session);

            if ($primary !== null) {
                return ConsumableInventoryType::label($primary);
            }

            if (self::classesForSession($session)->count() > 1) {
                return '';
            }

            if (filled($session->inventory_type_label)) {
                return (string) $session->inventory_type_label;
            }

            return ConsumableInventoryType::label($session->inventory_type);
        }

        $primary = self::primaryClass($session);

        if ($primary !== null) {
            return $session->count_type === PhysicalCountSession::TYPE_RPCPPE
                ? PpePropertyType::propertyTypeLabel($primary)
                : ItemPropertyClass::propertyTypeLabel($primary);
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
        if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE) {
            if (filled($session->ppe_type)) {
                return PpePropertyType::options()[$session->ppe_type]
                    ?? PpePropertyType::propertyTypeLabel($session->ppe_type);
            }
        }

        if ($session->count_type === PhysicalCountSession::TYPE_RPCSP && filled($session->property_class)) {
            return ItemPropertyClass::options()[$session->property_class]
                ?? (string) $session->property_class;
        }

        $classes = self::classesForSession($session);

        if ($classes->isEmpty()) {
            return 'Set after loading count lines';
        }

        if ($classes->count() > 1) {
            return 'Multiple property classes';
        }

        $class = (string) $classes->first();

        if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE) {
            return PpePropertyType::options()[$class] ?? $class;
        }

        return ItemPropertyClass::options()[$class] ?? $class;
    }

    public static function displayInventoryTypeText(PhysicalCountSession $session): string
    {
        if ($session->count_type === PhysicalCountSession::TYPE_RPCI) {
            $classes = self::classesForSession($session);

            if ($classes->isEmpty()) {
                return filled($session->inventory_type_label)
                    ? (string) $session->inventory_type_label
                    : (filled($session->inventory_type)
                        ? ConsumableInventoryType::label($session->inventory_type)
                        : 'Set after adding or loading count lines');
            }

            if ($classes->count() > 1) {
                return 'Multiple inventory types';
            }

            return ConsumableInventoryType::label((string) $classes->first());
        }

        if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE && filled($session->ppe_type)) {
            return PpePropertyType::propertyTypeLabel($session->ppe_type);
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

        $class = (string) $classes->first();

        return $session->count_type === PhysicalCountSession::TYPE_RPCPPE
            ? PpePropertyType::propertyTypeLabel($class)
            : ItemPropertyClass::propertyTypeLabel($class);
    }

    public static function syncSession(PhysicalCountSession $session): PhysicalCountSession
    {
        if (! in_array($session->count_type, [
            PhysicalCountSession::TYPE_RPCSP,
            PhysicalCountSession::TYPE_RPCPPE,
            PhysicalCountSession::TYPE_RPCI,
        ], true)) {
            return $session;
        }

        $session->loadMissing('lines.item');
        $primary = self::primaryClass($session);

        if ($session->count_type === PhysicalCountSession::TYPE_RPCI) {
            if ($primary !== null) {
                $session->update([
                    'inventory_type' => $primary,
                    'inventory_type_label' => ConsumableInventoryType::label($primary),
                    'property_class' => null,
                    'ppe_type' => null,
                ]);

                return $session->fresh();
            }

            if (self::classesForSession($session)->count() > 1) {
                $session->update([
                    'inventory_type' => null,
                    'inventory_type_label' => null,
                    'property_class' => null,
                    'ppe_type' => null,
                ]);

                return $session->fresh();
            }

            return $session;
        }

        if ($primary !== null) {
            if ($session->count_type === PhysicalCountSession::TYPE_RPCSP) {
                $session->update([
                    'property_class' => $primary,
                    'ppe_type' => null,
                    'inventory_type' => null,
                    'inventory_type_label' => ItemPropertyClass::propertyTypeLabel($primary),
                ]);
            } else {
                $session->update([
                    'ppe_type' => $primary,
                    'property_class' => null,
                    'inventory_type' => null,
                    'inventory_type_label' => PpePropertyType::propertyTypeLabel($primary),
                ]);
            }

            return $session->fresh();
        }

        if (self::classesForSession($session)->count() > 1) {
            $session->update([
                'property_class' => null,
                'ppe_type' => null,
                'inventory_type' => null,
                'inventory_type_label' => null,
            ]);

            return $session->fresh();
        }

        return $session;
    }
}
