<?php

namespace App\Filament\Widgets;

use App\Models\ItemCategory;
use App\Services\ProcurementDecisionSupportService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Livewire\Attributes\Url;

class ProjectedStockoutsWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = 5;

    protected static bool $isLazy = true;

    protected string $view = 'filament.widgets.projected-stockouts-widget';

    protected int|string|array $columnSpan = 'full';

    #[Url]
    public string $categoryId = '';

    /** When false, category is controlled by the parent (e.g. Procurement analytics page). */
    public bool $showCategoryFilter = true;

    public ?string $from = null;

    public ?string $to = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('categoryId')
                ->label('Category')
                ->options(ItemCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder('All categories')
                ->native(false),
        ]);
    }

    public function getRows(): \Illuminate\Support\Collection
    {
        $user = Filament::auth()->user();
        $officeIds = $user?->office_id ? [(int) $user->office_id] : [];

        $from = $this->from
            ? Carbon::parse($this->from)->startOfDay()
            : now()->subMonths(11)->startOfMonth();

        $to = $this->to
            ? Carbon::parse($this->to)->endOfDay()
            : now()->endOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $categoryId = $this->categoryId !== '' ? (int) $this->categoryId : null;

        return app(ProcurementDecisionSupportService::class)->getProjectedStockouts(
            from: $from,
            to: $to,
            categoryId: $categoryId,
            officeIds: $officeIds,
            withinMonths: 2,
            limit: 10,
        );
    }
}
