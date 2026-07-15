<?php

namespace App\Filament\Resources\Distributions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListDistributions extends ListRecords
{
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = DistributionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Distributions';
    }

    public function getHeading(): string|Htmlable
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $user->isUnitConsolidator()) {
            return 'Distributions';
        }

        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (! $categoryName) {
            return 'Distributions';
        }

        return new HtmlString($this->getWizardHeaderBreadcrumb($categoryName, 'Distributions'));
    }

    public function getRecord(): mixed
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        $user = Filament::auth()->user();
        $redirectWhenMissing = ! ($user instanceof User && $user->isUnitConsolidator());

        $this->syncActiveItemCategoryFromRequest($redirectWhenMissing);
    }

    public function updatedCategory(): void
    {
        session()->put('active_item_category_id', (int) $this->category);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        $classes = parent::getPageClasses();

        $user = Filament::auth()->user();

        if ($user instanceof User && $user->isUnitConsolidator()) {
            $classes[] = 'owwa-distributions-uc-toolbar';
        }

        return $classes;
    }

    public function content(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $user->isUnitConsolidator()) {
            static $hookRegistered = false;

            if (! $hookRegistered) {
                $hookRegistered = true;

                FilamentView::registerRenderHook(
                    TablesRenderHook::TOOLBAR_SEARCH_BEFORE,
                    fn (): HtmlString => new HtmlString(
                        (string) view('filament.resources.distributions.partials.category-bar')
                    ),
                    scopes: static::class,
                );
            }
        }

        return parent::content($schema);
    }

    protected function getWizardHeaderBreadcrumb(string $categoryName, string $taskLabel): string
    {
        $categoryId = $this->activeItemCategoryId();
        $dashboardUrl = InventoryCategoryDashboard::getUrl(['category' => $categoryId]);

        return sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">%s</span></span>',
            e($dashboardUrl),
            e($categoryName),
            e($taskLabel),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            OwwaFormModalDefaults::createActionForResource(DistributionResource::class, OwwaFormModalDefaults::WIDTH_COMPACT)
                ->mutateDataUsing(function (array $data): array {
                    $user = Filament::auth()->user();

                    if ($user) {
                        $data['distributed_by'] = $user->id;

                        if ($user->office_id) {
                            $data['office_id'] = (int) $user->office_id;
                        }

                        if ($user->department_id) {
                            $data['department_id'] = (int) $user->department_id;
                        }
                    }

                    return $data;
                })
                ->visible(fn (): bool => DistributionResource::canCreate()),
        ];
    }
}
