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
     * Official OWWA instruction wording for a signatory role (Appendix PDFs).
     */
    public static function roleInstruction(string $role): ?string
    {
        return match ($role) {
            ProcurementSignatoryName::ROLE_REQUESTED => 'Name of the person requesting the purchase of the item/s.',
            ProcurementSignatoryName::ROLE_REQUESTED_DESIGNATION => 'Signature, printed name and designation of the person requesting the purchase of the item/s.',
            ProcurementSignatoryName::ROLE_APPROVED => 'Name of the person approving the purchase of the item/s.',
            ProcurementSignatoryName::ROLE_APPROVED_DESIGNATION => 'Signature, printed name and designation of the person approving the purchase of the item/s.',
            ProcurementSignatoryName::ROLE_INSPECTION_OFFICER => 'Signs the Inspection portion: inspected, verified, and found in order as to quantity and specifications.',
            ProcurementSignatoryName::ROLE_CUSTODIAN => 'Acknowledges receipt on Acceptance: name, signature, date, and complete/partial mark.',
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED_DESIGNATION => 'Signature over printed name and designation of Agency/Entity Head (or Head of Supply/Property Unit) and the date of approval.',
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED_DESIGNATION => 'Signature over printed name and designation of the assigned releasing officer of the Supply and/or Property Unit and the date released.',
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED,
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED_DESIGNATION => 'Signature over printed name and designation of the assigned receiving officer or employee/user and the date of receipt.',
            ProcurementSignatoryName::ROLE_TRANSFER_FROM_ACCOUNTABLE => 'Name of the accountable officer/agency/fund cluster where the property is located.',
            ProcurementSignatoryName::ROLE_TRANSFER_TO_ACCOUNTABLE => 'Name of the accountable officer/agency/fund cluster where the property is to be transferred.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE_DESIGNATION => 'Name and official designation of the accountable officer.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED => 'Certified correct by the Inventory Committee Chair and Members.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED => 'Approved by the Head of Agency/Entity or his/her authorized representative.',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED => 'Verified by the COA Representative.',
            ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS => 'Name of the person who witnessed the disposal.',
            ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_DESIGNATION => 'Designation of the Authorized Official approving inspection and disposition.',
            ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_DESIGNATION => 'Designation of the Accountable Officer.',
            ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_STATION => 'Station of the Accountable Officer.',
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
            'inspection_officer' => 'Name of the Inspection Officer.',
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
            ProcurementSignatoryName::ROLE_REQUESTED_DESIGNATION => 'Requested by (designation)',
            ProcurementSignatoryName::ROLE_APPROVED => 'Approve By',
            ProcurementSignatoryName::ROLE_APPROVED_DESIGNATION => 'Approved by (designation)',
            ProcurementSignatoryName::ROLE_INSPECTION_OFFICER => 'Inspection officer',
            ProcurementSignatoryName::ROLE_CUSTODIAN => 'Custodian / accountable officer',
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED => 'Transfer — Approved by',
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED_DESIGNATION => 'Transfer — Approved by designation',
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED => 'Transfer — Released by',
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED_DESIGNATION => 'Transfer — Released by designation',
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED => 'Transfer — Received by',
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED_DESIGNATION => 'Transfer — Received by designation',
            ProcurementSignatoryName::ROLE_TRANSFER_FROM_ACCOUNTABLE => 'Transfer — From accountable officer',
            ProcurementSignatoryName::ROLE_TRANSFER_TO_ACCOUNTABLE => 'Transfer — To accountable officer',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE => 'Physical count — Accountable officer',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE_DESIGNATION => 'Physical count — Accountable designation',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED => 'Physical count — Certified by',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED => 'Physical count — Approved by',
            ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED => 'Physical count — Verified by',
            ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS => 'Disposal — Witness',
            ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_DESIGNATION => 'Disposal — Authorized official designation',
            ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_DESIGNATION => 'Disposal — Accountable designation',
            ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_STATION => 'Disposal — Accountable station',
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
                ProcurementSignatoryName::ROLE_REQUESTED_DESIGNATION,
                ProcurementSignatoryName::ROLE_APPROVED,
                ProcurementSignatoryName::ROLE_APPROVED_DESIGNATION,
                ProcurementSignatoryName::ROLE_INSPECTION_OFFICER,
                ProcurementSignatoryName::ROLE_CUSTODIAN,
            ],
            'transfer' => [
                ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
                ProcurementSignatoryName::ROLE_TRANSFER_APPROVED_DESIGNATION,
                ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
                ProcurementSignatoryName::ROLE_TRANSFER_RELEASED_DESIGNATION,
                ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED,
                ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED_DESIGNATION,
                ProcurementSignatoryName::ROLE_TRANSFER_FROM_ACCOUNTABLE,
                ProcurementSignatoryName::ROLE_TRANSFER_TO_ACCOUNTABLE,
            ],
            'physical_count' => [
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE_DESIGNATION,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_APPROVED,
                ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_VERIFIED,
            ],
            'disposal' => [
                ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS,
                ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_DESIGNATION,
                ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_DESIGNATION,
                ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_STATION,
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
