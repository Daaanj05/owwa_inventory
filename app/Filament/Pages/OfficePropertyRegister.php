<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\OfficePropertyRegisterService;
use App\Support\SemiExpendableUsefulLife;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class OfficePropertyRegister extends Page
{
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Office property register';

    protected static ?string $title = 'Office property register';

    protected string $view = 'filament.pages.office-property-register';

    #[Url]
    public string $sortBy = 'issuance_date';

    #[Url]
    public string $sortDir = 'desc';

    #[Url]
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isUnitConsolidator();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Office property register';
    }

    public function getHeading(): string|Htmlable
    {
        $dashboardUrl = route('filament.admin.pages.dashboard');

        return new HtmlString(sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">Inventory</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">Office property register</span></span>',
            e($dashboardUrl),
        ));
    }

    public function getSubheading(): ?string
    {
        return 'PPE and semi-expendable property issued to you or your office. Useful life applies to semi-expendable only.';
    }

    /** @return array<int, string> */
    public function getPageClasses(): array
    {
        return ['owwa-inv-category-page', 'owwa-office-property-register'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['property_number', 'item_name', 'category_name', 'issuance_date', 'estimated_useful_life', 'eul_expires_at'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function eulStatusLabel(?string $slug, ?string $status): string
    {
        if ($slug !== 'semi_expendable') {
            return 'N/A';
        }

        return SemiExpendableUsefulLife::statusLabel($status);
    }

    public function getPropertyRows(): LengthAwarePaginator
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1);
        }

        return app(OfficePropertyRegisterService::class)->paginateForUser(
            $user,
            $this->search,
            $this->sortBy,
            $this->sortDir,
        );
    }
}
