<?php

namespace App\Support;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\AiProcurementRun;
use App\Models\DeliveryTerm;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\DisposalBatch;
use App\Models\Distribution;
use App\Models\InspectionAcceptanceReport;
use App\Models\Issuance;
use App\Models\IssuanceBatch;
use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PropertyActionRequest;
use App\Models\PurchaseOrder;
use App\Models\ReferenceSeries;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\Transfer;
use App\Models\UsefulLifeExtension;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class UserActivityLogDisplay
{
    /** @var array<string, ?string> */
    protected static array $relatedLabels = [];

    /** @var list<string> */
    protected const HIDDEN_ATTRIBUTES = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'email_verification_code_hash',
        'reset_code_hash',
        'updated_at',
        'created_at',
    ];

    /** @var array<string, string> */
    protected const ATTRIBUTE_LABELS = [
        'office_id' => 'Office',
        'department_id' => 'Sub-Office/Department',
        'item_id' => 'Item',
        'item_category_id' => 'Category',
        'requisition_id' => 'Requisition',
        'requested_by' => 'Requested by',
        'approved_by' => 'Approved by',
        'endorsed_by' => 'Endorsed by',
        'user_id' => 'User',
        'recorded_by' => 'Recorded by',
        'supplier_id' => 'Supplier',
        'reference_code' => 'Reference no.',
        'transaction_number' => 'Transaction no.',
        'status' => 'Status',
        'purpose' => 'Purpose',
        'quantity' => 'Quantity',
        'remarks' => 'Remarks',
        'role' => 'Role',
        'name' => 'Name',
        'email' => 'Email',
    ];

    /**
     * Subjects needed to write the summary line. Detail relations stay unloaded.
     *
     * @param  Collection<int, UserActivityLog>  $activities
     */
    public static function hydrateSummaries(Collection $activities): void
    {
        self::loadSubjects($activities, [
            RequisitionItem::class => ['item', 'requisition'],
            Distribution::class => ['item', 'requisition'],
            UsefulLifeExtension::class => ['issuance.batch', 'issuance.item'],
            SupplierAddress::class => ['supplier'],
            Issuance::class => ['batch'],
            Disposal::class => ['batch'],
        ]);
    }

    /**
     * @param  Collection<int, UserActivityLog>  $activities
     */
    public static function hydrate(Collection $activities): void
    {
        self::loadSubjects($activities, [
            Requisition::class => ['office', 'department', 'requestedBy'],
            RequisitionItem::class => ['item', 'requisition.office', 'requisition.department'],
            User::class => ['office', 'department', 'assignments.office', 'assignments.department'],
            Issuance::class => ['batch', 'item', 'office', 'department', 'requisition', 'issuedTo'],
            IssuanceBatch::class => ['office', 'department', 'requisition'],
            Disposal::class => ['batch', 'item', 'office', 'department'],
            DisposalBatch::class => ['office', 'department'],
            Distribution::class => ['item', 'requisition', 'office', 'department', 'distributedTo'],
            UsefulLifeExtension::class => ['issuance.batch', 'issuance.item', 'approvedBy'],
            SupplierAddress::class => ['supplier'],
            AiProcurementRun::class => ['creator'],
            Acquisition::class => ['item', 'office'],
            AcquisitionPaperwork::class => ['office', 'department'],
            PurchaseOrder::class => ['supplier', 'purchaseRequest'],
            InspectionAcceptanceReport::class => ['purchaseOrder'],
            Transfer::class => ['item', 'fromOffice', 'toOffice'],
            PropertyActionRequest::class => ['office', 'department'],
            PhysicalCountSession::class => ['office', 'itemCategory'],
            PhysicalInventoryPlan::class => ['itemCategory'],
            Item::class => ['category'],
            Department::class => ['office'],
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function detailsFor(UserActivityLog $activity): array
    {
        self::hydrate(new Collection([$activity]));

        return self::details($activity);
    }

    /**
     * @param  Collection<int, UserActivityLog>  $activities
     * @param  array<class-string, array<int, string>>  $with
     */
    protected static function loadSubjects(Collection $activities, array $with): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        $activities->load([
            'subject' => function (MorphTo $morphTo) use ($with): void {
                $morphTo
                    ->constrain([
                        Acquisition::class => fn ($query) => $query->withTrashed(),
                        AiProcurementRun::class => fn ($query) => $query->withTrashed(),
                        Disposal::class => fn ($query) => $query->withTrashed(),
                        Issuance::class => fn ($query) => $query->withTrashed(),
                        PhysicalInventoryPlan::class => fn ($query) => $query->withTrashed(),
                        Transfer::class => fn ($query) => $query->withTrashed(),
                    ])
                    ->morphWith($with);
            },
        ]);
    }

    public static function summary(UserActivityLog $activity): string
    {
        $subject = $activity->subject;

        if ($subject instanceof Model) {
            $described = self::describe($subject, (string) $activity->action);

            if ($described !== '') {
                return $described;
            }
        }

        return (string) $activity->summary;
    }

    public static function describe(Model $model, string $action): string
    {
        $verb = match ($action) {
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'fulfilled' => 'Fulfilled',
            'archived' => 'Archived',
            'restored' => 'Restored',
            'status_changed' => 'Changed status of',
            default => ucfirst(str_replace('_', ' ', $action)),
        };

        $label = Str::headline(class_basename($model));
        $reference = self::reference($model);

        if ($reference !== null && $reference !== '') {
            return "{$verb} {$label} {$reference}";
        }

        return "{$verb} {$label}";
    }

    public static function reference(Model $model): ?string
    {
        if ($model instanceof Requisition) {
            return self::requisitionReference($model);
        }

        if ($model instanceof RequisitionItem) {
            return self::requisitionItemReference($model);
        }

        if ($model instanceof User) {
            $name = trim((string) $model->name);

            return $name !== '' ? $name : ($model->email ?: null);
        }

        if ($model instanceof Item) {
            return self::itemReference($model);
        }

        if ($model instanceof Distribution) {
            return self::distributionReference($model);
        }

        if ($model instanceof UsefulLifeExtension) {
            return self::usefulLifeReference($model);
        }

        if ($model instanceof ItemAttributeOption) {
            return self::attributeOptionReference($model);
        }

        if ($model instanceof SupplierAddress) {
            return self::supplierAddressReference($model);
        }

        if ($model instanceof AiProcurementRun) {
            return self::aiRunReference($model);
        }

        if ($model instanceof DeliveryTerm) {
            return filled($model->label) ? (string) $model->label : null;
        }

        if (method_exists($model, 'controlNumber')) {
            $control = $model->controlNumber();

            if (filled($control)) {
                return (string) $control;
            }
        }

        foreach (['transaction_number', 'reference_code', 'pr_number', 'number', 'item_code', 'name', 'email', 'code'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (filled($value) && is_scalar($value)) {
                return (string) $value;
            }
        }

        $key = $model->getKey();

        return $key !== null ? '#'.$key : null;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function details(UserActivityLog $activity): array
    {
        $lines = [];
        $subject = $activity->subject;

        if ($subject instanceof Model) {
            $lines = self::subjectFacts($subject);
        } elseif (trim((string) $activity->summary) !== '') {
            $lines[] = [
                'label' => 'Summary',
                'value' => (string) $activity->summary,
            ];
        }

        $stored = trim((string) $activity->summary);
        $displayed = self::summary($activity);
        $rebuiltActions = ['created', 'updated', 'deleted', 'approved', 'rejected', 'fulfilled', 'archived', 'restored', 'status_changed'];

        if ($stored !== '' && $stored !== $displayed && ! in_array((string) $activity->action, $rebuiltActions, true)) {
            $lines[] = [
                'label' => 'Logged as',
                'value' => $stored,
            ];
        }

        foreach (self::contextLines($activity) as $line) {
            $lines[] = $line;
        }

        $changes = self::changeLines($activity);

        if ($changes !== []) {
            $lines[] = [
                'label' => 'What changed',
                'value' => implode("\n", $changes),
            ];
        }

        if ($lines === []) {
            $lines[] = [
                'label' => 'Summary',
                'value' => (string) $activity->summary,
            ];
        }

        return $lines;
    }

    public static function requisitionReference(Requisition $requisition): ?string
    {
        if (filled($requisition->reference_code)) {
            return (string) $requisition->reference_code;
        }

        if (filled($requisition->transaction_number)) {
            return (string) $requisition->transaction_number;
        }

        $key = $requisition->getKey();

        return $key !== null ? '#'.$key : null;
    }

    protected static function requisitionItemReference(RequisitionItem $line): ?string
    {
        $line->loadMissing(['item', 'requisition']);

        $itemName = trim((string) ($line->item?->name ?? ''));
        $itemCode = trim((string) ($line->item?->item_code ?? ''));
        $itemLabel = $itemName !== '' ? $itemName : ($itemCode !== '' ? $itemCode : null);
        $parent = $line->requisition instanceof Requisition
            ? self::requisitionReference($line->requisition)
            : null;
        $parentIsNumber = filled($parent) && ! str_starts_with((string) $parent, '#');

        $parts = [];

        if ($itemLabel !== null) {
            $parts[] = $line->quantity !== null ? "{$itemLabel} × {$line->quantity}" : $itemLabel;
        } elseif ($line->quantity !== null) {
            $parts[] = "quantity {$line->quantity}";
        }

        if ($parentIsNumber) {
            $parts[] = "requisition {$parent}";
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $key = $line->getKey();

        return $key !== null ? '#'.$key : null;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function subjectFacts(Model $model): array
    {
        if ($model instanceof Requisition) {
            return self::requisitionFacts($model);
        }

        if ($model instanceof RequisitionItem) {
            return self::requisitionItemFacts($model);
        }

        if ($model instanceof User) {
            return self::userFacts($model);
        }

        if ($model instanceof ReferenceSeries) {
            return self::referenceSeriesFacts($model);
        }

        if ($model instanceof Distribution) {
            return self::distributionFacts($model);
        }

        if ($model instanceof UsefulLifeExtension) {
            return self::usefulLifeFacts($model);
        }

        if ($model instanceof ItemAttributeOption) {
            return self::attributeOptionFacts($model);
        }

        if ($model instanceof SupplierAddress) {
            return self::supplierAddressFacts($model);
        }

        if ($model instanceof AiProcurementRun) {
            return self::aiRunFacts($model);
        }

        if ($model instanceof DeliveryTerm) {
            return self::deliveryTermFacts($model);
        }

        if ($model instanceof Issuance) {
            return self::issuanceFacts($model);
        }

        if ($model instanceof IssuanceBatch) {
            return self::issuanceBatchFacts($model);
        }

        if ($model instanceof Disposal) {
            return self::disposalFacts($model);
        }

        if ($model instanceof DisposalBatch) {
            return self::disposalBatchFacts($model);
        }

        if ($model instanceof Acquisition) {
            return self::acquisitionFacts($model);
        }

        if ($model instanceof AcquisitionPaperwork) {
            return self::paperworkFacts($model);
        }

        if ($model instanceof PurchaseOrder) {
            return self::purchaseOrderFacts($model);
        }

        if ($model instanceof InspectionAcceptanceReport) {
            return self::inspectionReportFacts($model);
        }

        if ($model instanceof Transfer) {
            return self::transferFacts($model);
        }

        if ($model instanceof PropertyActionRequest) {
            return self::propertyActionFacts($model);
        }

        if ($model instanceof PhysicalCountSession) {
            return self::physicalCountFacts($model);
        }

        if ($model instanceof PhysicalInventoryPlan) {
            return self::inventoryPlanFacts($model);
        }

        if ($model instanceof Item) {
            return self::itemFacts($model);
        }

        if ($model instanceof ItemCategory) {
            return self::itemCategoryFacts($model);
        }

        if ($model instanceof Office) {
            return self::officeFacts($model);
        }

        if ($model instanceof Department) {
            return self::departmentFacts($model);
        }

        if ($model instanceof Supplier) {
            return self::supplierFacts($model);
        }

        return self::genericFacts($model);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function requisitionFacts(Requisition $requisition): array
    {
        $requisition->loadMissing(['office', 'department', 'requestedBy']);

        $lines = [
            ['label' => 'Record', 'value' => 'Requisition'],
        ];

        if (filled($requisition->transaction_number)) {
            $lines[] = ['label' => 'Transaction no.', 'value' => (string) $requisition->transaction_number];
        }

        if (filled($requisition->reference_code)) {
            $lines[] = ['label' => 'RIS no.', 'value' => (string) $requisition->reference_code];
        }

        if (filled($requisition->status)) {
            $lines[] = ['label' => 'Status', 'value' => Str::headline(str_replace('_', ' ', (string) $requisition->status))];
        }

        $lines[] = ['label' => 'Office', 'value' => $requisition->office?->name ?: '—'];
        $lines[] = ['label' => 'Sub-Office/Department', 'value' => $requisition->department?->name ?: '—'];

        if (filled($requisition->purpose)) {
            $lines[] = ['label' => 'Purpose', 'value' => Str::limit((string) $requisition->purpose, 300)];
        }

        if (filled($requisition->requestedBy?->name)) {
            $lines[] = ['label' => 'Requested by', 'value' => (string) $requisition->requestedBy->name];
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function requisitionItemFacts(RequisitionItem $line): array
    {
        $line->loadMissing(['item', 'requisition.office', 'requisition.department']);
        $requisition = $line->requisition;
        $itemLabel = trim((string) ($line->item?->name ?? ''));

        if ($itemLabel !== '' && filled($line->item?->item_code)) {
            $itemLabel .= ' ('.$line->item->item_code.')';
        }

        $lines = [
            ['label' => 'Record', 'value' => 'Requisition item'],
            ['label' => 'Item', 'value' => $itemLabel !== '' ? $itemLabel : '—'],
            ['label' => 'Quantity', 'value' => $line->quantity !== null ? (string) $line->quantity : '—'],
        ];

        if ($requisition instanceof Requisition) {
            $number = self::requisitionReference($requisition);

            if (filled($number)) {
                $lines[] = ['label' => 'Requisition', 'value' => (string) $number];
            }

            $lines[] = ['label' => 'Office', 'value' => $requisition->office?->name ?: '—'];
            $lines[] = ['label' => 'Sub-Office/Department', 'value' => $requisition->department?->name ?: '—'];
        }

        if (filled($line->remarks)) {
            $lines[] = ['label' => 'Remarks', 'value' => Str::limit((string) $line->remarks, 300)];
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function userFacts(User $user): array
    {
        $user->loadMissing(['office', 'department', 'assignments.office', 'assignments.department']);

        return [
            ['label' => 'Record', 'value' => 'User'],
            ['label' => 'Name', 'value' => $user->name ?: '—'],
            ['label' => 'Role', 'value' => $user->roleLabel()],
            ['label' => 'Email', 'value' => $user->email ?: '—'],
            ['label' => 'Office', 'value' => $user->assignmentOfficesSummary() ?: '—'],
            ['label' => 'Sub-Office/Department', 'value' => $user->assignmentDepartmentsSummary() ?: '—'],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function referenceSeriesFacts(ReferenceSeries $series): array
    {
        $lines = [
            ['label' => 'Record', 'value' => 'Reference series'],
            ['label' => 'Name', 'value' => $series->name ?: '—'],
        ];

        if (filled($series->type)) {
            $lines[] = [
                'label' => 'Type',
                'value' => Str::headline(str_replace('_', ' ', (string) $series->type)),
            ];
        }

        if (filled($series->prefix)) {
            $lines[] = ['label' => 'Prefix', 'value' => (string) $series->prefix];
        }

        if (filled($series->pattern)) {
            $lines[] = ['label' => 'Pattern', 'value' => (string) $series->pattern];
        }

        return $lines;
    }

    protected static function itemReference(Item $item): ?string
    {
        $name = trim((string) $item->name);
        $code = trim((string) $item->item_code);

        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }

        return $name !== '' ? $name : ($code !== '' ? $code : null);
    }

    protected static function distributionReference(Distribution $distribution): ?string
    {
        $distribution->loadMissing(['item', 'requisition']);
        $itemLabel = self::namedItem($distribution->item);
        $parent = $distribution->requisition instanceof Requisition
            ? self::requisitionReference($distribution->requisition)
            : null;
        $parts = [];

        if ($itemLabel !== null) {
            $parts[] = $distribution->quantity !== null ? "{$itemLabel} × {$distribution->quantity}" : $itemLabel;
        } elseif ($distribution->quantity !== null) {
            $parts[] = "quantity {$distribution->quantity}";
        }

        if (filled($parent) && ! str_starts_with((string) $parent, '#')) {
            $parts[] = "requisition {$parent}";
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        return $distribution->getKey() !== null ? '#'.$distribution->getKey() : null;
    }

    protected static function usefulLifeReference(UsefulLifeExtension $extension): ?string
    {
        $extension->loadMissing(['issuance.item', 'issuance.batch']);
        $issuance = $extension->issuance;
        $itemLabel = $issuance instanceof Issuance ? self::namedItem($issuance->item) : null;
        $number = $issuance instanceof Issuance ? $issuance->controlNumber() : null;
        $parts = [];

        if ($itemLabel !== null) {
            $parts[] = $itemLabel;
        }

        if (filled($number)) {
            $parts[] = "issuance {$number}";
        }

        if ($parts !== []) {
            $change = filled($extension->previous_eul) && filled($extension->new_eul)
                ? $extension->previous_eul.' → '.$extension->new_eul.' yr'
                : null;

            return $change !== null ? implode(' · ', $parts)." ({$change})" : implode(' · ', $parts);
        }

        return $extension->getKey() !== null ? '#'.$extension->getKey() : null;
    }

    protected static function attributeOptionReference(ItemAttributeOption $option): ?string
    {
        $kind = ItemAttributeOption::kindOptions()[$option->kind] ?? Str::headline(str_replace('_', ' ', (string) $option->kind));
        $label = trim((string) ($option->label ?: $option->value));

        if ($label === '') {
            return $kind !== '' ? $kind : null;
        }

        return "{$kind}: {$label}";
    }

    protected static function supplierAddressReference(SupplierAddress $address): ?string
    {
        $address->loadMissing('supplier');
        $name = trim((string) ($address->supplier?->name ?? ''));
        $text = trim((string) $address->address);

        if ($name !== '' && $text !== '') {
            return $name.' · '.Str::limit($text, 80);
        }

        return $name !== '' ? $name : ($text !== '' ? Str::limit($text, 80) : null);
    }

    protected static function aiRunReference(AiProcurementRun $run): ?string
    {
        if ($run->period_from !== null && $run->period_to !== null) {
            return self::dateText($run->period_from).' – '.self::dateText($run->period_to);
        }

        if ($run->ran_at !== null) {
            return self::dateText($run->ran_at, true);
        }

        return $run->getKey() !== null ? '#'.$run->getKey() : null;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function distributionFacts(Distribution $distribution): array
    {
        $distribution->loadMissing(['item', 'requisition', 'office', 'department', 'distributedTo']);
        $lines = self::record('Distribution');
        self::addLine($lines, 'Item', self::namedItem($distribution->item, true));
        self::addLine($lines, 'Quantity', $distribution->quantity);
        self::addLine($lines, 'Requisition', $distribution->requisition instanceof Requisition ? self::requisitionReference($distribution->requisition) : null);
        self::addLine($lines, 'Office', $distribution->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $distribution->department?->name);
        self::addLine($lines, 'Distributed to', $distribution->distributedTo?->name);
        self::addLine($lines, 'Date', self::dateText($distribution->distribution_date));
        self::addLine($lines, 'Remarks', $distribution->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function usefulLifeFacts(UsefulLifeExtension $extension): array
    {
        $extension->loadMissing(['issuance.item', 'issuance.batch', 'approvedBy']);
        $issuance = $extension->issuance;
        $lines = self::record('Useful life extension');
        self::addLine($lines, 'Issuance', $issuance instanceof Issuance ? $issuance->controlNumber() : null);
        self::addLine($lines, 'Item', $issuance instanceof Issuance ? self::namedItem($issuance->item, true) : null);
        self::addLine($lines, 'Previous useful life', filled($extension->previous_eul) ? $extension->previous_eul.' yr' : null);
        self::addLine($lines, 'New useful life', filled($extension->new_eul) ? $extension->new_eul.' yr' : null);
        self::addLine($lines, 'Previous expiry', self::dateText($extension->previous_expires_at));
        self::addLine($lines, 'New expiry', self::dateText($extension->new_expires_at));
        self::addLine($lines, 'Reason', $extension->reason);
        self::addLine($lines, 'Approved by', $extension->approvedBy?->name);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function attributeOptionFacts(ItemAttributeOption $option): array
    {
        $lines = self::record('Item attribute');
        self::addLine($lines, 'Kind', ItemAttributeOption::kindOptions()[$option->kind] ?? $option->kind);
        self::addLine($lines, 'Label', $option->label ?: $option->value);
        self::addLine($lines, 'Value', $option->value);
        self::addLine($lines, 'Active', $option->is_active ? 'Yes' : 'No');

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function supplierAddressFacts(SupplierAddress $address): array
    {
        $address->loadMissing('supplier');
        $lines = self::record('Supplier address');
        self::addLine($lines, 'Supplier', $address->supplier?->name);
        self::addLine($lines, 'Address', $address->address);
        self::addLine($lines, 'Default', $address->is_default ? 'Yes' : 'No');

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function aiRunFacts(AiProcurementRun $run): array
    {
        $run->loadMissing('creator');
        $lines = self::record('AI procurement run');
        self::addLine($lines, 'Ran at', self::dateText($run->ran_at, true));
        self::addLine($lines, 'Period', $run->period_from !== null && $run->period_to !== null
            ? self::dateText($run->period_from).' – '.self::dateText($run->period_to)
            : null);
        self::addLine($lines, 'Status', self::headline($run->status));
        self::addLine($lines, 'Summary', $run->summary);
        self::addLine($lines, 'Created by', $run->creator?->name);
        self::addLine($lines, 'Error', $run->error_message);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function deliveryTermFacts(DeliveryTerm $term): array
    {
        $lines = self::record('Delivery term');
        self::addLine($lines, 'Label', $term->label);
        self::addLine($lines, 'Active', $term->is_active ? 'Yes' : 'No');

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function issuanceFacts(Issuance $issuance): array
    {
        $issuance->loadMissing(['batch', 'item', 'office', 'department', 'requisition', 'issuedTo']);
        $lines = self::record('Issuance');
        self::addLine($lines, 'Control no.', $issuance->controlNumber());
        self::addLine($lines, 'Item', self::namedItem($issuance->item, true));
        self::addLine($lines, 'Quantity', $issuance->quantity);
        self::addLine($lines, 'Office', $issuance->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $issuance->department?->name);
        self::addLine($lines, 'Date', self::dateText($issuance->issuance_date));
        self::addLine($lines, 'Issued to', $issuance->issuedTo?->name ?: $issuance->received_by_printed_name);
        self::addLine($lines, 'Property no.', $issuance->issuedPropertyNumbersLabel() ?: $issuance->property_number);
        self::addLine($lines, 'Requisition', $issuance->requisition instanceof Requisition ? self::requisitionReference($issuance->requisition) : null);
        self::addLine($lines, 'Remarks', $issuance->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function issuanceBatchFacts(IssuanceBatch $batch): array
    {
        $batch->loadMissing(['office', 'department', 'requisition']);
        $lines = self::record('Issuance batch');
        self::addLine($lines, 'Control no.', $batch->reference_code);
        self::addLine($lines, 'Category', self::headline($batch->category_slug));
        self::addLine($lines, 'Office', $batch->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $batch->department?->name);
        self::addLine($lines, 'Date', self::dateText($batch->issuance_date));
        self::addLine($lines, 'Requisition', $batch->requisition instanceof Requisition ? self::requisitionReference($batch->requisition) : null);
        self::addLine($lines, 'Remarks', $batch->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function disposalFacts(Disposal $disposal): array
    {
        $disposal->loadMissing(['batch', 'item', 'office', 'department']);
        $lines = self::record('Disposal');
        self::addLine($lines, 'Control no.', $disposal->controlNumber());
        self::addLine($lines, 'Item', self::namedItem($disposal->item, true));
        self::addLine($lines, 'Quantity', $disposal->quantity);
        self::addLine($lines, 'Office', $disposal->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $disposal->department?->name);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function disposalBatchFacts(DisposalBatch $batch): array
    {
        $batch->loadMissing(['office', 'department']);
        $lines = self::record('Disposal batch');
        self::addLine($lines, 'Control no.', $batch->reference_code);
        self::addLine($lines, 'Category', self::headline($batch->category_slug));
        self::addLine($lines, 'Type', self::headline($batch->disposal_type));
        self::addLine($lines, 'Mode', self::headline($batch->disposal_mode));
        self::addLine($lines, 'Office', $batch->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $batch->department?->name);
        self::addLine($lines, 'Date', self::dateText($batch->disposal_date));
        self::addLine($lines, 'Remarks', $batch->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function acquisitionFacts(Acquisition $acquisition): array
    {
        $acquisition->loadMissing(['item', 'office']);
        $lines = self::record('Acquisition');
        self::addLine($lines, 'Reference no.', $acquisition->reference_code);
        self::addLine($lines, 'Item', self::namedItem($acquisition->item, true));
        self::addLine($lines, 'Quantity', $acquisition->quantity);
        self::addLine($lines, 'Office', $acquisition->office?->name);
        self::addLine($lines, 'Date', self::dateText($acquisition->acquisition_date));
        self::addLine($lines, 'Source', $acquisition->source);
        self::addLine($lines, 'Remarks', $acquisition->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function paperworkFacts(AcquisitionPaperwork $paperwork): array
    {
        $paperwork->loadMissing(['office', 'department']);
        $lines = self::record('Purchase request');
        self::addLine($lines, 'Reference no.', $paperwork->reference_code);
        self::addLine($lines, 'PR no.', $paperwork->pr_number);
        self::addLine($lines, 'Office', $paperwork->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $paperwork->department?->name);
        self::addLine($lines, 'Purpose', $paperwork->purpose);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function purchaseOrderFacts(PurchaseOrder $order): array
    {
        $order->loadMissing(['supplier', 'purchaseRequest']);
        $lines = self::record('Purchase order');
        self::addLine($lines, 'PO no.', $order->number);
        self::addLine($lines, 'Supplier', $order->supplier?->name ?: $order->supplier_name);
        self::addLine($lines, 'Status', self::headline($order->status));
        self::addLine($lines, 'Date', self::dateText($order->po_date));
        self::addLine($lines, 'Purchase request', $order->purchaseRequest?->pr_number ?: $order->purchaseRequest?->reference_code);
        self::addLine($lines, 'Remarks', $order->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function inspectionReportFacts(InspectionAcceptanceReport $report): array
    {
        $report->loadMissing('purchaseOrder');
        $lines = self::record('Inspection acceptance report');
        self::addLine($lines, 'IAR no.', $report->number);
        self::addLine($lines, 'Status', self::headline($report->status));
        self::addLine($lines, 'Date', self::dateText($report->iar_date));
        self::addLine($lines, 'Purchase order', $report->purchaseOrder?->number);
        self::addLine($lines, 'Invoice no.', $report->invoice_number);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function transferFacts(Transfer $transfer): array
    {
        $transfer->loadMissing(['item', 'fromOffice', 'toOffice']);
        $lines = self::record('Transfer');
        self::addLine($lines, 'Reference no.', $transfer->reference_code);
        self::addLine($lines, 'Item', self::namedItem($transfer->item, true));
        self::addLine($lines, 'Quantity', $transfer->quantity);
        self::addLine($lines, 'From office', $transfer->fromOffice?->name);
        self::addLine($lines, 'To office', $transfer->toOffice?->name);
        self::addLine($lines, 'Type', filled($transfer->transfer_type)
            ? Transfer::typeLabel($transfer->transfer_type, $transfer->transfer_type_other)
            : null);
        self::addLine($lines, 'Date', self::dateText($transfer->transfer_date));
        self::addLine($lines, 'Property no.', $transfer->property_number);
        self::addLine($lines, 'Remarks', $transfer->remarks);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function propertyActionFacts(PropertyActionRequest $request): array
    {
        $request->loadMissing(['office', 'department']);
        $lines = self::record('Property action');
        self::addLine($lines, 'Reference no.', $request->reference_code);
        self::addLine($lines, 'Action', self::headline($request->action_type));
        self::addLine($lines, 'Status', self::headline($request->status));
        self::addLine($lines, 'Office', $request->office?->name);
        self::addLine($lines, 'Sub-Office/Department', $request->department?->name);
        self::addLine($lines, 'Reason', $request->reason_detail ?: self::headline($request->reason_code));

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function physicalCountFacts(PhysicalCountSession $session): array
    {
        $session->loadMissing(['office', 'itemCategory']);
        $lines = self::record('Physical count');
        self::addLine($lines, 'Reference no.', $session->reference_code);
        self::addLine($lines, 'Count type', self::headline($session->count_type));
        self::addLine($lines, 'Status', self::headline($session->status));
        self::addLine($lines, 'Office', $session->office?->name);
        self::addLine($lines, 'Category', $session->itemCategory?->name);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function inventoryPlanFacts(PhysicalInventoryPlan $plan): array
    {
        $plan->loadMissing('itemCategory');
        $lines = self::record('Inventory plan');
        self::addLine($lines, 'Reference no.', $plan->reference_code);
        self::addLine($lines, 'Title', $plan->title);
        self::addLine($lines, 'Period', $plan->period_label);
        self::addLine($lines, 'Status', self::headline($plan->status));
        self::addLine($lines, 'Category', $plan->itemCategory?->name);
        self::addLine($lines, 'Cut-off', self::dateText($plan->cut_off_date));

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function itemFacts(Item $item): array
    {
        $item->loadMissing('category');
        $lines = self::record('Item');
        self::addLine($lines, 'Name', $item->name);
        self::addLine($lines, 'Item code', $item->item_code);
        self::addLine($lines, 'Unit', $item->unit);
        self::addLine($lines, 'Category', $item->category?->name);
        self::addLine($lines, 'Description', $item->description);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function itemCategoryFacts(ItemCategory $category): array
    {
        $lines = self::record('Item category');
        self::addLine($lines, 'Name', $category->name);
        self::addLine($lines, 'Description', $category->description);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function officeFacts(Office $office): array
    {
        $lines = self::record('Office');
        self::addLine($lines, 'Name', $office->name);
        self::addLine($lines, 'Code', $office->code);
        self::addLine($lines, 'Address', $office->address);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function departmentFacts(Department $department): array
    {
        $department->loadMissing('office');
        $lines = self::record('Sub-Office/Department');
        self::addLine($lines, 'Name', $department->name);
        self::addLine($lines, 'Code', $department->code);
        self::addLine($lines, 'Office', $department->office?->name);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function supplierFacts(Supplier $supplier): array
    {
        $lines = self::record('Supplier');
        self::addLine($lines, 'Name', $supplier->name);
        self::addLine($lines, 'TIN', $supplier->tin);

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function record(string $name): array
    {
        return [
            ['label' => 'Record', 'value' => $name],
        ];
    }

    /**
     * @param  list<array{label: string, value: string}>  $lines
     */
    protected static function addLine(array &$lines, string $label, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === false) {
            return;
        }

        $lines[] = [
            'label' => $label,
            'value' => Str::limit(is_scalar($value) ? (string) $value : 'updated', 300),
        ];
    }

    protected static function namedItem(?Item $item, bool $withCode = false): ?string
    {
        if (! $item instanceof Item) {
            return null;
        }

        $name = trim((string) $item->name);
        $code = trim((string) $item->item_code);

        if ($withCode && $name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }

        return $name !== '' ? $name : ($code !== '' ? $code : null);
    }

    protected static function headline(mixed $value): ?string
    {
        if (! filled($value) || ! is_scalar($value)) {
            return null;
        }

        return Str::headline(str_replace('_', ' ', (string) $value));
    }

    protected static function dateText(mixed $value, bool $withTime = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance(\DateTimeImmutable::createFromInterface($value))
                : Carbon::parse((string) $value);

            return $date->timezone((string) config('app.timezone'))->format($withTime ? 'M j, Y g:i A' : 'M j, Y');
        } catch (\Throwable) {
            return is_scalar($value) ? (string) $value : null;
        }
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function contextLines(UserActivityLog $activity): array
    {
        $properties = $activity->properties ?? [];
        $lines = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key) || in_array($key, ['changed', 'old', 'new', 'raw_response'], true)) {
                continue;
            }

            if (in_array($key, self::HIDDEN_ATTRIBUTES, true)) {
                continue;
            }

            if ($key === 'ids' && is_array($value)) {
                $count = count($value);
                self::addLine($lines, 'Records', $count.' record'.($count === 1 ? '' : 's'));

                continue;
            }

            self::addLine($lines, self::attributeLabel($key), self::displayValue($key, $value));
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    protected static function genericFacts(Model $model): array
    {
        $lines = [
            ['label' => 'Record', 'value' => Str::headline(class_basename($model))],
        ];

        $reference = self::reference($model);

        if (filled($reference)) {
            $lines[] = ['label' => 'Reference', 'value' => (string) $reference];
        }

        if (filled($model->getAttribute('status')) && is_scalar($model->getAttribute('status'))) {
            $lines[] = [
                'label' => 'Status',
                'value' => Str::headline(str_replace('_', ' ', (string) $model->getAttribute('status'))),
            ];
        }

        if (method_exists($model, 'office')) {
            $model->loadMissing('office');
            $officeName = $model->getRelation('office')?->name ?? null;

            if (filled($officeName)) {
                $lines[] = ['label' => 'Office', 'value' => (string) $officeName];
            }
        }

        if (method_exists($model, 'department')) {
            $model->loadMissing('department');
            $departmentName = $model->getRelation('department')?->name ?? null;

            if (filled($departmentName)) {
                $lines[] = ['label' => 'Sub-Office/Department', 'value' => (string) $departmentName];
            }
        }

        if (method_exists($model, 'item')) {
            $model->loadMissing('item');
            $item = $model->getRelation('item');
            self::addLine($lines, 'Item', $item instanceof Item ? self::namedItem($item, true) : null);
        }

        self::addLine($lines, 'Quantity', $model->getAttribute('quantity'));
        self::addLine($lines, 'Purpose', $model->getAttribute('purpose'));
        self::addLine($lines, 'Remarks', $model->getAttribute('remarks'));

        foreach (['issuance_date', 'acquisition_date', 'transfer_date', 'disposal_date', 'distribution_date', 'po_date', 'iar_date'] as $dateAttribute) {
            $date = self::dateText($model->getAttribute($dateAttribute));

            if ($date !== null) {
                self::addLine($lines, 'Date', $date);

                break;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected static function changeLines(UserActivityLog $activity): array
    {
        $properties = $activity->properties ?? [];
        $changed = $properties['changed'] ?? [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['new'] ?? null) ? $properties['new'] : [];

        if (! is_array($changed)) {
            return [];
        }

        $lines = [];

        foreach ($changed as $key) {
            if (! is_string($key) || in_array($key, self::HIDDEN_ATTRIBUTES, true)) {
                continue;
            }

            if ($key === 'original_submission') {
                $lines[] = 'Original submission: updated';

                continue;
            }

            $before = self::displayValue($key, $old[$key] ?? null);
            $after = self::displayValue($key, $new[$key] ?? null);

            if ($before === $after) {
                continue;
            }

            $lines[] = self::attributeLabel($key).': '.$before.' → '.$after;
        }

        return $lines;
    }

    protected static function attributeLabel(string $key): string
    {
        return self::ATTRIBUTE_LABELS[$key] ?? Str::headline(str_replace('_', ' ', $key));
    }

    protected static function displayValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $encoded = json_encode($value);

            if ($encoded === false || strlen($encoded) > 180) {
                return 'updated';
            }

            return $encoded;
        }

        if (! is_scalar($value)) {
            return 'updated';
        }

        if ($key === 'role') {
            $user = new User;
            $user->role = (string) $value;

            return $user->roleLabel();
        }

        if (str_ends_with($key, '_id') || in_array($key, ['requested_by', 'approved_by', 'endorsed_by', 'recorded_by'], true)) {
            if (is_numeric($value)) {
                return self::relatedLabel($key, (int) $value) ?? '#'.$value;
            }
        }

        if ($key === 'status') {
            return Str::headline(str_replace('_', ' ', (string) $value));
        }

        if (str_ends_with($key, '_at') || str_ends_with($key, '_date')) {
            try {
                return Carbon::parse((string) $value)->timezone(config('app.timezone'))->format('M j, Y g:i A');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return Str::limit((string) $value, 180);
    }

    protected static function relatedLabel(string $key, int $id): ?string
    {
        $cacheKey = "{$key}:{$id}";

        if (array_key_exists($cacheKey, self::$relatedLabels)) {
            return self::$relatedLabels[$cacheKey];
        }

        $label = match ($key) {
            'office_id' => Office::query()->whereKey($id)->value('name'),
            'department_id' => Department::query()->whereKey($id)->value('name'),
            'item_id' => Item::query()->whereKey($id)->value('name'),
            'item_category_id' => ItemCategory::query()->whereKey($id)->value('name'),
            'supplier_id' => Supplier::query()->whereKey($id)->value('name'),
            'requested_by', 'approved_by', 'endorsed_by', 'user_id', 'recorded_by' => User::query()->whereKey($id)->value('name'),
            'requisition_id' => self::requisitionLabelById($id),
            default => null,
        };

        self::$relatedLabels[$cacheKey] = $label !== null ? (string) $label : null;

        return self::$relatedLabels[$cacheKey];
    }

    protected static function requisitionLabelById(int $id): ?string
    {
        $requisition = Requisition::query()->find($id);

        if (! $requisition instanceof Requisition) {
            return null;
        }

        return self::requisitionReference($requisition);
    }
}
