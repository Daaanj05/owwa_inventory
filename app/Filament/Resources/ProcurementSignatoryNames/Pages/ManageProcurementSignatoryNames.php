<?php

namespace App\Filament\Resources\ProcurementSignatoryNames\Pages;

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
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageProcurementSignatoryNames extends ManageRecords
{
    use HasSetupArchiveView;

    protected static string $resource = ProcurementSignatoryNameResource::class;

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
                ->form($this->signatoryFormComponents())
                ->fillForm(fn (): array => [
                    'role' => SignatorySelect::defaultRoleForTab($this->activeTab),
                ]),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->recordActions([
                EditAction::make()
                    ->form($this->signatoryFormComponents())
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
            Select::make('role')
                ->label('Role')
                ->options(function (?ProcurementSignatoryName $record = null): array {
                    return SignatorySelect::roleOptionsForTabIncluding(
                        is_string($this->activeTab) ? $this->activeTab : null,
                        $record?->role,
                    );
                })
                ->required()
                ->searchable()
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
                ->label('Printed name / designation')
                ->required()
                ->maxLength(255),
        ];
    }
}
