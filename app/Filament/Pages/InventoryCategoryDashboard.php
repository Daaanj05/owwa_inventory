<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class InventoryCategoryDashboard extends Page
{
    use SyncsActiveItemCategory;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.inventory-category-dashboard';

    #[Url]
    public int|string|null $category = null;

    public ?ItemCategory $categoryRecord = null;

    public function mount(): void
    {
        $this->resolveCategoryRecord();
    }

    public function updatedCategory(): void
    {
        $this->resolveCategoryRecord();
    }

    protected function resolveCategoryRecord(): void
    {
        $categoryId = filled($this->category)
            ? (int) $this->category
            : (int) request()->query('category', 0);

        if ($categoryId <= 0) {
            $categoryId = (int) session('active_item_category_id', 0);
        }

        $this->categoryRecord = ItemCategory::query()->find($categoryId);

        if (! $this->categoryRecord) {
            abort(404);
        }

        $this->category = $this->categoryRecord->id;
        session()->put('active_item_category_id', $this->categoryRecord->id);
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user && ! $user->isSystemAdmin() && ! $user->isEmployee();
    }

    public function getTitle(): string|Htmlable
    {
        return (string) $this->categoryRecord?->name;
    }

    public function getSubheading(): ?string
    {
        $user = Filament::auth()->user();
        $slug = $this->categoryRecord?->getTemplateSlug();

        if ($user instanceof User && $user->isUnitConsolidator() && in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return 'On-hand quantity at your office. Issued property you hold appears on Office property register.';
        }

        if (in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return 'On-hand quantity only. Issued property remains on PAR/ICS and property registers.';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        if (! $this->categoryRecord) {
            return ['owwa-inv-category-page'];
        }

        return [
            'owwa-inv-category-page',
            'owwa-icd--'.Str::slug($this->categoryRecord->name),
        ];
    }

    /** @return array{total: int, lowCount: int, okCount: int} */
    public function getStockSummary(): array
    {
        $rows = $this->getCategoryStockRows();
        $total = $rows->count();
        $lowCount = $rows->where('is_low', true)->count();

        return [
            'total' => $total,
            'lowCount' => $lowCount,
            'okCount' => $total - $lowCount,
        ];
    }

    public function getCategoryLabel(): string
    {
        return (string) $this->categoryRecord?->name;
    }

    /** @return array<int, array{title: string, description: string, icon: string, url: string}> */
    public function getTaskCards(): array
    {
        $categoryId = $this->category;

        $cards = [
            [
                'title' => 'Stock levels',
                'description' => 'View stock on hand and low-stock alerts for this category.',
                'icon' => 'heroicon-o-squares-2x2',
                'url' => StockLevels::getUrl(['category' => $categoryId]),
            ],
        ];

        $user = Filament::auth()->user();
        if ($user?->isSupplyCustodian()) {
            $cards[] = [
                'title' => 'Items',
                'description' => 'Register and maintain catalog items for this category.',
                'icon' => 'heroicon-o-cube',
                'url' => static::urlWithActiveItemCategory(ItemResource::getUrl('index'), $categoryId),
            ];
            $cards[] = [
                'title' => 'Acquisitions',
                'description' => 'PR → PO → IAR workflow and custodian receipts for this category.',
                'icon' => 'heroicon-o-arrow-down-tray',
                'url' => static::urlWithActiveItemCategory(AcquisitionResource::getUrl('index'), $categoryId),
            ];
            $cards[] = [
                'title' => 'Issuances',
                'description' => 'Review and create issuance records for this category.',
                'icon' => 'heroicon-o-arrow-up-tray',
                'url' => static::urlWithActiveItemCategory(IssuanceResource::getUrl('index'), $categoryId),
            ];
            $disposalDescription = match ($this->categoryRecord?->getTemplateSlug()) {
                'consumables' => 'Waste materials disposal (WMR) for this category.',
                'ppe', 'semi_expendable' => 'Unserviceable property disposal (IIRUP) for this category.',
                default => 'Review and create disposal records for this category.',
            };

            $cards[] = [
                'title' => 'Disposals',
                'description' => $disposalDescription,
                'icon' => 'heroicon-o-trash',
                'url' => static::urlWithActiveItemCategory(DisposalResource::getUrl('index'), $categoryId),
            ];

            if ($this->categoryRecord?->getTemplateSlug() !== 'consumables') {
                $cards[] = [
                    'title' => 'Transfers',
                    'description' => 'Review and create transfer records for this category.',
                    'icon' => 'heroicon-o-arrows-right-left',
                    'url' => static::urlWithActiveItemCategory(TransferResource::getUrl('index'), $categoryId),
                ];
            }

            $cards[] = [
                'title' => 'Physical counts',
                'description' => 'Conduct physical count sessions and export RPCI, RPCPPE, or RPCSP forms.',
                'icon' => 'heroicon-o-clipboard-document-check',
                'url' => static::urlWithActiveItemCategory(PhysicalCountSessionResource::getUrl('index'), $categoryId),
            ];
            $cards[] = [
                'title' => 'Inventory Schedule',
                'description' => 'Schedule year-end counts by office and date; get reminders when counts are due.',
                'icon' => 'heroicon-o-calendar-days',
                'url' => static::urlWithActiveItemCategory(PhysicalInventoryPlanResource::getUrl('index'), $categoryId),
            ];
        }

        if ($user instanceof User && $user->isUnitConsolidator()) {
            $slug = $this->categoryRecord?->getTemplateSlug();

            if (in_array($slug, ['ppe', 'semi_expendable'], true)) {
                $cards[] = [
                    'title' => 'Office property register',
                    'description' => 'View PPE and semi-expendable property issued to you, including useful life status.',
                    'icon' => 'heroicon-o-clipboard-document-list',
                    'url' => OfficePropertyRegister::getUrl(),
                ];
            }

            $cards[] = [
                'title' => 'Regional supply catalog',
                'description' => 'Browse items and stock at the regional supply office before submitting a requisition.',
                'icon' => 'heroicon-o-building-storefront',
                'url' => RegionalSupplyCatalog::getUrl(['category' => $categoryId]),
            ];
            $cards[] = [
                'title' => 'Distributions',
                'description' => 'Record items distributed to Employees for this category.',
                'icon' => 'heroicon-o-gift',
                'url' => static::urlWithActiveItemCategory(DistributionResource::getUrl('index'), $categoryId),
            ];
        }

        return $cards;
    }

    /** @return Collection<int, object> */
    protected function getCategoryStockRows(): Collection
    {
        $rows = app(InventoryStockService::class)->getStockLevelsList();
        $user = Filament::auth()->user();

        if ($user && $user->office_id) {
            $rows = $rows->where('office_id', (int) $user->office_id)->values();
        }

        if ($this->categoryRecord) {
            $rows = $rows->where('category_name', $this->categoryRecord->name)->values();
        }

        return $rows;
    }
}
