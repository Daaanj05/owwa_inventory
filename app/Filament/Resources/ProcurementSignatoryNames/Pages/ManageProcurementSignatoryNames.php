<?php

namespace App\Filament\Resources\ProcurementSignatoryNames\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Resources\ProcurementSignatoryNames\ProcurementSignatoryNameResource;
use App\Models\ProcurementSignatoryName;
use App\Support\SignatorySelect;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

class ManageProcurementSignatoryNames extends ManageRecords
{
    use HasSearchRowToolbarActions;
    use HasSetupArchiveView;

    protected static string $resource = ProcurementSignatoryNameResource::class;

    protected static bool $signatoryFormTabsHookRegistered = false;

    public function boot(): void
    {
        $this->registerSignatoryFormTabsHook();
    }

    public function mount(): void
    {
        parent::mount();

        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        $roles = SignatorySelect::rolesForTab(is_string($this->activeTab) ? $this->activeTab : null);

        return ProcurementSignatoryName::query()
            ->whereNotNull('archived_at')
            ->when($roles !== [], fn (Builder $query): Builder => $query->whereIn('role', $roles))
            ->count();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function registerSignatoryFormTabsHook(): void
    {
        if (static::$signatoryFormTabsHookRegistered) {
            return;
        }

        static::$signatoryFormTabsHookRegistered = true;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function (): HtmlString {
                $livewire = Livewire::current();

                if (! $livewire instanceof self) {
                    return new HtmlString('');
                }

                return new HtmlString((string) view('filament.tables.signatory-form-tabs', [
                    'labels' => SignatorySelect::tabLabels(),
                    'counts' => $livewire->signatoryTabCounts(),
                    'activeTab' => $livewire->activeTab,
                ]));
            },
            scopes: self::class,
        );
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'owwa-setup-archive-toggle',
            'owwa-search-row-toolbar',
            'owwa-signatory-toolbar',
        ];
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        if ($this->showingArchived) {
            return [];
        }

        return [
            [
                'label' => 'New Signatory',
                'action' => 'create',
                'style' => 'primary',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function signatoryTabCounts(): array
    {
        $counts = [];

        foreach (SignatorySelect::roleGroups() as $key => $roles) {
            $counts[$key] = ProcurementSignatoryName::query()
                ->active()
                ->whereIn('role', $roles)
                ->count();
        }

        return $counts;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pr_iar';
    }

    public function getTabs(): array
    {
        $labels = SignatorySelect::tabLabels();
        $tabs = [];

        foreach (SignatorySelect::roleGroups() as $key => $roles) {
            $tabs[$key] = Tab::make($labels[$key] ?? $key)
                ->badge(fn (): int => ProcurementSignatoryName::query()->active()->whereIn('role', $roles)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('role', $roles));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ! $this->showingArchived)
                ->modalWidth(Width::ExtraLarge)
                ->extraModalWindowAttributes(['class' => 'owwa-signatory-modal'])
                ->createAnotherAction(fn ($action) => $action->label('Save & add another'))
                ->form($this->signatoryFormComponents())
                ->fillForm(fn (): array => [
                    'form' => is_string($this->activeTab) ? $this->activeTab : null,
                    'role' => SignatorySelect::defaultRoleForTab(is_string($this->activeTab) ? $this->activeTab : null),
                ])
                ->mutateFormDataUsing(fn (array $data): array => $this->clearUnusedDesignation($data)),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::ExtraLarge)
                    ->extraModalWindowAttributes(['class' => 'owwa-signatory-modal'])
                    ->form($this->signatoryFormComponents())
                    ->fillForm(fn (ProcurementSignatoryName $record): array => [
                        'form' => SignatorySelect::tabForRole($record->role),
                        'role' => $record->role,
                        'name' => $record->name,
                        'designation' => $record->designation,
                    ])
                    ->mutateFormDataUsing(fn (array $data): array => $this->clearUnusedDesignation($data))
                    ->visible(fn (ProcurementSignatoryName $record): bool => ! $record->isArchived()),
                ProcurementSignatoryNameResource::archiveAction(),
                ProcurementSignatoryNameResource::restoreAction(),
            ])
            ->emptyStateHeading(fn (): string => $this->showingArchived
                ? 'No archived signatories'
                : 'No signatories yet')
            ->emptyStateDescription(fn (): string => $this->showingArchived
                ? 'Archived names for this category will appear here.'
                : 'Add printed names and designations used on procurement and inventory forms.');
    }

    /**
     * @return array<int, Placeholder|Select|TextInput>
     */
    protected function signatoryFormComponents(): array
    {
        return [
            Select::make('form')
                ->label('Form')
                ->options(SignatorySelect::tabLabels())
                ->placeholder('Select an option')
                ->selectablePlaceholder(false)
                ->default(function (?ProcurementSignatoryName $record): ?string {
                    if ($record !== null) {
                        return SignatorySelect::tabForRole($record->role);
                    }

                    return is_string($this->activeTab) ? $this->activeTab : null;
                })
                ->required()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                    $role = $get('role');
                    if (! is_string($role) || $role === '') {
                        return;
                    }

                    if (SignatorySelect::tabForRole($role) !== $state) {
                        $set('role', null);
                    }
                }),
            Select::make('role')
                ->label('Role')
                ->options(function (Get $get, ?ProcurementSignatoryName $record = null): array {
                    $tab = $get('form');
                    if (! is_string($tab) || $tab === '') {
                        $tab = $record !== null
                            ? SignatorySelect::tabForRole($record->role)
                            : (is_string($this->activeTab) ? $this->activeTab : null);
                    }

                    return SignatorySelect::roleOptionsForTabIncluding($tab, $record?->role);
                })
                ->required()
                ->live(),
            Placeholder::make('role_instruction')
                ->hiddenLabel()
                ->content(function (Get $get): \Illuminate\Support\HtmlString {
                    $role = (string) ($get('role') ?? '');
                    $text = SignatorySelect::roleInstruction($role) ?? '';

                    if ($text === '') {
                        return new \Illuminate\Support\HtmlString('');
                    }

                    return new \Illuminate\Support\HtmlString(
                        '<p wire:key="signatory-role-instruction-'.e($role).'" class="owwa-signatory-role-instruction">'
                        .e($text)
                        .'</p>'
                    );
                })
                ->visible(fn (Get $get): bool => filled(SignatorySelect::roleInstruction((string) ($get('role') ?? '')))),
            TextInput::make('name')
                ->label(fn (Get $get): string => self::nameFieldLabel((string) ($get('role') ?? '')))
                ->required()
                ->maxLength(255)
                ->autocomplete(false)
                ->extraInputAttributes(['autocomplete' => 'off']),
            TextInput::make('designation')
                ->label('Designation')
                ->required(fn (Get $get): bool => ProcurementSignatoryName::roleStoresDesignation((string) ($get('role') ?? '')))
                ->visible(fn (Get $get): bool => ProcurementSignatoryName::roleStoresDesignation((string) ($get('role') ?? '')))
                ->maxLength(255)
                ->autocomplete(false)
                ->extraInputAttributes(['autocomplete' => 'off'])
                ->helperText('Official title printed with this person’s name.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clearUnusedDesignation(array $data): array
    {
        unset($data['form']);

        if (! ProcurementSignatoryName::roleStoresDesignation((string) ($data['role'] ?? ''))) {
            $data['designation'] = null;
        }

        return $data;
    }

    protected static function nameFieldLabel(string $role): string
    {
        return $role === ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER
            ? 'Name'
            : 'Printed name';
    }
}
