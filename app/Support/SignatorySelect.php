<?php

namespace App\Support;

use App\Models\ProcurementSignatoryName;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

class SignatorySelect
{
    /**
     * Searchable Select of saved signatory names for a role (Setup → Signatories only; no inline create).
     */
    public static function make(string $name, string $role): Select
    {
        return Select::make($name)
            ->options(function (Get $get) use ($name, $role): array {
                return self::optionsIncludingCurrent($role, $get($name));
            })
            ->searchable()
            ->preload()
            ->helperText(fn (): ?string => self::roleInstruction($role));
    }

    /**
     * @param  callable(Get): array<int, string>  $suggestionList
     */
    public static function makeFromSuggestions(string $name, string $role, callable $suggestionList): Select
    {
        return Select::make($name)
            ->options(function (Get $get) use ($name, $suggestionList): array {
                $list = $suggestionList($get);
                $options = collect($list)
                    ->mapWithKeys(fn (string $value): array => [$value => $value])
                    ->all();
                $current = $get($name);
                if (filled($current) && ! array_key_exists((string) $current, $options)) {
                    $options[(string) $current] = (string) $current;
                }

                return $options;
            })
            ->searchable()
            ->preload()
            ->helperText(fn (): ?string => self::roleInstruction($role));
    }

    /**
     * Designation choices saved on signatories of a printed-name role.
     */
    public static function makeDesignation(string $name, string $printedNameRole): Select
    {
        return Select::make($name)
            ->label('Designation')
            ->options(function (Get $get) use ($name, $printedNameRole): array {
                $options = collect(ProcurementSignatoryName::designationsForRole($printedNameRole))
                    ->mapWithKeys(fn (string $value): array => [$value => $value])
                    ->all();
                $current = $get($name);
                if (filled($current) && ! array_key_exists((string) $current, $options)) {
                    $options[(string) $current] = (string) $current;
                }

                return $options;
            })
            ->searchable()
            ->preload()
            ->helperText('Designation of the selected person');
    }

    /**
     * Official OWWA instruction wording for a signatory role (Appendix PDFs).
     */
    public static function roleInstruction(string $role): ?string
    {
        return match ($role) {
            ProcurementSignatoryName::ROLE_REQUESTED => 'Name of the person requesting the purchase of the item/s.',
            ProcurementSignatoryName::ROLE_APPROVED => 'Name of the person approving the purchase of the item/s.',
            ProcurementSignatoryName::ROLE_INSPECTION_OFFICER => 'Signs the Inspection portion: inspected, verified, and found in order as to quantity and specifications.',
            ProcurementSignatoryName::ROLE_CUSTODIAN => 'Acknowledges receipt on Acceptance: name, signature, date, and complete/partial mark.',
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED => 'Name of the Approver',
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED => 'Name of the Issuer',
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED => 'Name of the Receiver',
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED_DESIGNATION,
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED_DESIGNATION,
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED_DESIGNATION => 'Designation of the selected person',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE => 'Name of the accountable officer for whom this inventory is held.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED => 'Name of Inventory Committee Chair and Member.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED => 'Name of Head of Agency/Entity or authorized representative.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED => 'Name of COA Representative.',
            ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER => 'Name of the accountable officer on the disposal report.',
            ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL => 'Name of the authorized official approving inspection and disposition.',
            ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS => 'Name of the person who witnessed the disposal.',
            ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER => 'Name of the inspection officer on the disposal report.',
            default => null,
        };
    }

    /**
     * Disposal-form overrides when shared roles (custodian / approved / inspection) mean IIRUP/WMR, not PR/IAR.
     */
    public static function disposalInstruction(string $key): ?string
    {
        return match ($key) {
            'custodian_consumables' => 'Name of the Supply / Property Custodian.',
            'custodian_iirup' => 'Name of the Accountable Officer.',
            'approved_consumables' => 'Name of the Head / Authorized Representative.',
            'approved_iirup' => 'Name of the Head / Authorized Representative.',
            'inspection_officer' => 'Name of the inspection officer on the disposal report.',
            'witness' => 'Name of the person who witnessed the disposal.',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function optionsIncludingCurrent(string $role, mixed $current): array
    {
        $options = ProcurementSignatoryName::optionsForRole($role);
        if (filled($current) && ! array_key_exists((string) $current, $options)) {
            $options[(string) $current] = (string) $current;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            ProcurementSignatoryName::ROLE_REQUESTED => 'Requested By',
            ProcurementSignatoryName::ROLE_APPROVED => 'Approve By',
            ProcurementSignatoryName::ROLE_INSPECTION_OFFICER => 'Inspection officer',
            ProcurementSignatoryName::ROLE_CUSTODIAN => 'Custodian / accountable officer',
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED => 'Transfer — Approved by',
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED => 'Transfer — Released by',
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED => 'Transfer — Received by',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE => 'Physical count — Accountable Officer',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED => 'Physical count — Certified Correct by',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED => 'Physical count — Approved by',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED => 'Physical count — Verified by',
            ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER => 'Disposal — Accountable officer',
            ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL => 'Disposal — Authorized official',
            ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER => 'Disposal — Inspection officer name',
            ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS => 'Disposal — Witness',
        ];
    }

    /**
     * Tab key => role constants for Signatories manage-page tabs.
     *
     * @return array<string, list<string>>
     */
    public static function roleGroups(): array
    {
        return [
            'pr_iar' => [
                ProcurementSignatoryName::ROLE_REQUESTED,
                ProcurementSignatoryName::ROLE_APPROVED,
                ProcurementSignatoryName::ROLE_INSPECTION_OFFICER,
                ProcurementSignatoryName::ROLE_CUSTODIAN,
            ],
            'transfer' => [
                ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
                ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
                ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED,
            ],
            'physical_count' => [
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED,
            ],
            'disposal' => [
                ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER,
                ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL,
                ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER,
                ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function rolesForTab(?string $tab): array
    {
        if ($tab === null || $tab === 'all') {
            return array_keys(self::roleOptions());
        }

        return self::roleGroups()[$tab] ?? [];
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptionsForTab(?string $tab): array
    {
        if ($tab === null || $tab === 'all') {
            return self::roleOptions();
        }

        $all = self::roleOptions();
        $options = [];
        foreach (self::rolesForTab($tab) as $role) {
            if (array_key_exists($role, $all)) {
                $options[$role] = $all[$role];
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptionsForTabIncluding(?string $tab, mixed $currentRole): array
    {
        $options = self::roleOptionsForTab($tab);
        if (filled($currentRole) && ! array_key_exists((string) $currentRole, $options)) {
            $all = self::roleOptions();
            $options[(string) $currentRole] = $all[(string) $currentRole] ?? (string) $currentRole;
        }

        return $options;
    }

    public static function tabForRole(?string $role): ?string
    {
        if ($role === null || $role === '') {
            return null;
        }

        foreach (self::roleGroups() as $tab => $roles) {
            if (in_array($role, $roles, true)) {
                return $tab;
            }
        }

        return null;
    }

    public static function defaultRoleForTab(?string $tab): ?string
    {
        if ($tab === null || $tab === 'all') {
            return null;
        }

        $roles = self::rolesForTab($tab);

        return $roles[0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function tabLabels(): array
    {
        return [
            'pr_iar' => 'PR / IAR',
            'transfer' => 'Transfer',
            'physical_count' => 'Physical count',
            'disposal' => 'Disposal',
        ];
    }
}
