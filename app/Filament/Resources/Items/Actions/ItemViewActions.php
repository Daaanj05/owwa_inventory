<?php

namespace App\Filament\Resources\Items\Actions;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\OwwaItemReportService;
use App\Support\CustodianOfficeScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Redirect;

class ItemViewActions
{
    public static function exportOwwaItemReportAction(): Action
    {
        return Action::make('exportOwwaItemReport')
            ->label(fn (): string => self::exportLabel())
            ->icon('heroicon-o-document-arrow-down')
            ->form([
                Select::make('form')
                    ->label('Form')
                    ->options(fn (Item $record): array => app(OwwaItemReportService::class)->getAvailableItemReportForms($record))
                    ->required(),
                Select::make('office_id')
                    ->label('Office (optional)')
                    ->options(fn (): array => CustodianOfficeScope::officeQuery(Office::query())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                    ->placeholder(fn (): string => CustodianOfficeScope::hasFixedInventoryOffice()
                        ? 'Your office'
                        : 'All offices')
                    ->hidden(fn (): bool => CustodianOfficeScope::hasFixedInventoryOffice()),
            ])
            ->action(function (Item $record, array $data) {
                $url = route('owwa.export.item', $record).'?form='.urlencode($data['form']);
                $officeId = $data['office_id'] ?? CustodianOfficeScope::inventoryOfficeId();
                if ($officeId) {
                    $url .= '&office_id='.(int) $officeId;
                }

                return Redirect::away($url);
            });
    }

    protected static function exportLabel(): string
    {
        $categoryId = session('active_item_category_id');
        if (! filled($categoryId)) {
            return 'Export OWWA item report';
        }

        $category = ItemCategory::query()->find((int) $categoryId);
        $slug = $category?->getTemplateSlug();

        return match ($slug) {
            'consumables' => 'Export Stock Card (XLS)',
            'ppe' => 'Export Property Card (XLS)',
            'semi_expendable' => 'Export property form (XLS)',
            default => 'Export OWWA item report',
        };
    }
}
