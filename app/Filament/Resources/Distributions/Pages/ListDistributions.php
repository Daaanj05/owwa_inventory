<?php

namespace App\Filament\Resources\Distributions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListDistributions extends ListRecords
{
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    protected static string $resource = DistributionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Distributions';
    }

    public function getHeading(): string|Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey((int) session('active_item_category_id'))->value('name');

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

        $this->syncActiveItemCategoryFromRequest();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    protected function getWizardHeaderBreadcrumb(string $categoryName, string $taskLabel): string
    {
        $categoryId = (int) session('active_item_category_id', 0);
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
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_COMPACT)
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
