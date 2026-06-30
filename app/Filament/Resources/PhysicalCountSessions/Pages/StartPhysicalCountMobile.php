<?php

namespace App\Filament\Resources\PhysicalCountSessions\Pages;

use App\Filament\Resources\PhysicalCountSessions\Concerns\HasPhysicalCountWizardBreadcrumbs;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class StartPhysicalCountMobile extends Page
{
    use HasPhysicalCountWizardBreadcrumbs;

    protected static string $resource = PhysicalCountSessionResource::class;

    protected static ?string $title = 'Start physical count';

    protected static ?string $navigationLabel = 'Start count (mobile)';

    protected string $view = 'filament.resources.physical-count-sessions.pages.start-physical-count-mobile';

    public ?int $officeId = null;

    public ?int $itemCategoryId = null;

    public function mount(): void
    {
        $categoryId = session('active_item_category_id') ? (int) session('active_item_category_id') : null;

        if ($categoryId) {
            $slug = ItemCategory::query()->find($categoryId)?->getTemplateSlug();
            if (in_array($slug, ['ppe', 'semi_expendable'], true)) {
                $this->itemCategoryId = $categoryId;
            }
        }

        $this->officeId = CustodianOfficeScope::inventoryOfficeId();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Start physical count';
    }

    public function getHeading(): string|Htmlable
    {
        return $this->physicalCountBreadcrumbHtml([
            ['label' => 'Start count'],
        ]);
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-physical-count-start-page'];
    }

    public function hasFixedOffice(): bool
    {
        return CustodianOfficeScope::hasFixedInventoryOffice();
    }

    public function fixedOfficeName(): ?string
    {
        if ($this->officeId === null) {
            return null;
        }

        return Office::query()->whereKey($this->officeId)->value('name');
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function officeOptions(): array
    {
        return CustodianOfficeScope::officeOptions();
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function categoryOptions(): array
    {
        return ItemCategory::query()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->filter(fn (ItemCategory $category): bool => in_array($category->getTemplateSlug(), ['ppe', 'semi_expendable'], true))
            ->map(fn (ItemCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->getTemplateSlug(),
            ])
            ->values()
            ->all();
    }

    public function startCount(): void
    {
        if ($this->hasFixedOffice()) {
            $this->officeId = CustodianOfficeScope::inventoryOfficeId();
        }

        $this->validate([
            'officeId' => ['required', 'integer', 'exists:offices,id'],
            'itemCategoryId' => ['required', 'integer', 'exists:item_categories,id'],
        ]);

        try {
            CustodianOfficeScope::assertOfficeAllowed((int) $this->officeId);
        } catch (ValidationException $exception) {
            $this->addError('officeId', collect($exception->errors())->flatten()->first() ?? 'Invalid office.');

            return;
        }

        $category = ItemCategory::query()->findOrFail($this->itemCategoryId);
        $slug = $category->getTemplateSlug();

        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            $this->addError('itemCategoryId', 'Only PPE and semi-expendable categories support QR counting.');

            return;
        }

        $countType = $slug === 'ppe'
            ? PhysicalCountSession::TYPE_RPCPPE
            : PhysicalCountSession::TYPE_RPCSP;

        $defaults = OfficeSignatoryDefaults::forPhysicalCountSession($this->officeId);

        $session = PhysicalCountSession::query()->create([
            'count_type' => $countType,
            'status' => PhysicalCountSession::STATUS_IN_PROGRESS,
            'office_id' => $this->officeId,
            'item_category_id' => $this->itemCategoryId,
            'count_date' => now()->toDateString(),
            'inventory_type_label' => $category->name,
            ...$defaults,
        ]);

        $this->redirect(PhysicalCountSessionResource::getUrl('scan', ['record' => $session]));
    }
}
