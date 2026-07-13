<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Exceptions\InvalidFieldMergeException;
use JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardSharedTenant;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardTeamShare;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Services\LeadFieldMergeService;
use Throwable;

class LeadBoardResource extends Resource
{
    protected static ?string $model = LeadBoard::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    public static function getModelLabel(): string
    {
        return __('lead-pipeline::lead-pipeline.board.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lead-pipeline::lead-pipeline.board.plural');
    }

    public static function getNavigationLabel(): string
    {
        return config('lead-pipeline.navigation.label', __('lead-pipeline::lead-pipeline.navigation.label'));
    }

    public static function getNavigationSort(): ?int
    {
        return config('lead-pipeline.navigation.sort', 10);
    }

    public static function getNavigationGroup(): ?string
    {
        return config('lead-pipeline.navigation.group');
    }

    /**
     * Returns the schema components contributed by registered
     * board form extensions (apps register these via
     * FilamentLeadPipelinePlugin::extendBoardForm()).
     *
     * @return array<int, mixed>
     */
    public static function getBoardFormExtensionComponents(): array
    {
        try {
            $plugin = filament()->getPlugin('filament-lead-pipeline');
        } catch (Throwable) {
            return [];
        }

        $components = [];
        foreach ($plugin->getBoardFormExtensions() as $closure) {
            $components = [...$components, ...$closure()];
        }

        return $components;
    }

    /**
     * Returns the schema components contributed by activated integrations'
     * boardFormComponents() (registered via
     * FilamentLeadPipelinePlugin::integrations()).
     *
     * Only meaningful on the edit form: the contract requires an existing
     * LeadBoard, which is not available yet on the create form, so a null
     * record short-circuits to no components. Fail-closed per integration:
     * isActivatedFor() and boardFormComponents() are each guarded - a throw
     * skips only that integration instead of breaking the form.
     *
     * @return array<int, mixed>
     */
    public static function getIntegrationBoardFormComponents(?Model $record): array
    {
        if ( ! $record instanceof LeadBoard) {
            return [];
        }

        try {
            $plugin = filament()->getPlugin('filament-lead-pipeline');
        } catch (Throwable) {
            return [];
        }

        if ( ! $plugin instanceof FilamentLeadPipelinePlugin) {
            return [];
        }

        $tenant = filament()->getTenant();

        if ( ! $tenant instanceof Model) {
            return [];
        }

        $components = [];

        foreach ($plugin->getIntegrations() as $integration) {
            try {
                if ( ! $integration->isActivatedFor($tenant)) {
                    continue;
                }

                $integrationComponents = $integration->boardFormComponents($record);
            } catch (Throwable) {
                continue;
            }

            $components = [...$components, ...$integrationComponents];
        }

        return $components;
    }

    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->visibleToTenant($tenant);
    }

    public static function getEloquentQuery(): Builder
    {
        return LeadBoard::query()->visibleToTenant(filament()->getTenant());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('lead-pipeline::lead-pipeline.board.details'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('lead-pipeline::lead-pipeline.field.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->label(__('lead-pipeline::lead-pipeline.field.description'))
                            ->rows(3),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('lead-pipeline::lead-pipeline.board.active'))
                            ->default(true),
                        Forms\Components\Select::make('admins')
                            ->label(__('lead-pipeline::lead-pipeline.board.admins'))
                            ->relationship(
                                'admins',
                                'first_name',
                                modifyQueryUsing: function ($query) {
                                    $modifier = FilamentLeadPipelinePlugin::getAssignableUsersQuery();

                                    return $modifier ? $modifier($query) : $query;
                                },
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->name . ' (' . $record->email . ')')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText(__('lead-pipeline::lead-pipeline.board.admins_helper')),
                        Forms\Components\Select::make('settings.auto_move_on_assign_phase')
                            ->label(__('lead-pipeline::lead-pipeline.board.auto_move_phase'))
                            ->options(
                                fn (?LeadBoard $record): array => $record
                                ? $record->phases()->ordered()->pluck('name', \JohnWink\FilamentLeadPipeline\Models\LeadPhase::pkColumn())->toArray()
                                : []
                            )
                            ->placeholder(__('lead-pipeline::lead-pipeline.board.auto_move_none'))
                            ->helperText(__('lead-pipeline::lead-pipeline.board.auto_move_help')),
                    ]),

                Forms\Components\Section::make(__('lead-pipeline::lead-pipeline.board.phases'))
                    ->schema([
                        Forms\Components\Placeholder::make('lock_warning_phases')
                            ->label('')
                            ->content(__('lead-pipeline::lead-pipeline.board.has_leads_hint'))
                            ->visible(fn (?LeadBoard $record): bool => (bool) $record?->hasLeads())
                            ->extraAttributes(['class' => 'text-sm text-warning-600 dark:text-warning-400']),
                        Forms\Components\Repeater::make('phases')
                            ->label(__('lead-pipeline::lead-pipeline.board.phases'))
                            ->hiddenLabel()
                            ->relationship('phases')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('lead-pipeline::lead-pipeline.field.name'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\ColorPicker::make('color')
                                    ->label(__('lead-pipeline::lead-pipeline.field.color'))
                                    ->default('#6B7280'),
                                Forms\Components\Select::make('type')
                                    ->label(__('lead-pipeline::lead-pipeline.field.type'))
                                    ->options(LeadPhaseTypeEnum::class)
                                    ->default(LeadPhaseTypeEnum::InProgress->value)
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                        if ($state && in_array($state, ['won', 'lost', 'disqualified'])) {
                                            $set('display_type', 'list');
                                        }
                                    }),
                                Forms\Components\Radio::make('display_type')
                                    ->label(__('lead-pipeline::lead-pipeline.phase.display_type'))
                                    ->options(LeadPhaseDisplayTypeEnum::class)
                                    ->default('kanban')
                                    ->inline(),
                                Forms\Components\Toggle::make('auto_convert')
                                    ->label(__('lead-pipeline::lead-pipeline.phase.auto_convert'))
                                    ->reactive(),
                                Forms\Components\Select::make('conversion_target')
                                    ->label(__('lead-pipeline::lead-pipeline.phase.conversion_target'))
                                    ->visible(fn (Forms\Get $get): bool => (bool) $get('auto_convert'))
                                    ->required(fn (Forms\Get $get): bool => (bool) $get('auto_convert'))
                                    ->helperText(__('lead-pipeline::lead-pipeline.board_edit.converter_help'))
                                    ->options(fn (): array => static::registeredConverterOptions())
                                    ->rules([
                                        fn (): string => 'in:' . implode(',', array_map(
                                            static fn ($key): string => (string) $key,
                                            array_keys(static::registeredConverterOptions()),
                                        )),
                                    ])
                                    ->placeholder(__('lead-pipeline::lead-pipeline.phase.converter_select')),
                            ])
                            ->orderColumn('sort')
                            ->reorderable()
                            ->addable()
                            ->deletable()
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(function (array $state): ?Htmlable {
                                $name = $state['name'] ?? null;

                                if (blank($name)) {
                                    return null;
                                }

                                $color = is_string($state['color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $state['color'])
                                    ? $state['color']
                                    : '#6B7280';

                                return new HtmlString(sprintf(
                                    '<span class="flex items-center gap-2"><span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background-color: %s"></span>%s</span>',
                                    $color,
                                    e($name),
                                ));
                            })
                            ->defaultItems(0)
                            ->columns(2),
                    ]),

                Forms\Components\Section::make(__('lead-pipeline::lead-pipeline.board.custom_fields'))
                    ->schema([
                        Forms\Components\Repeater::make('fieldDefinitions')
                            ->label(__('lead-pipeline::lead-pipeline.board.custom_fields'))
                            ->hiddenLabel()
                            ->relationship('fieldDefinitions', fn ($query) => $query->where('is_system', false)->withCount('values'))
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('lead-pipeline::lead-pipeline.field.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                        if (blank($state) || filled($get('key'))) {
                                            return;
                                        }

                                        $set('key', Str::slug($state, '_'));
                                    }),
                                Forms\Components\TextInput::make('key')
                                    ->label(__('lead-pipeline::lead-pipeline.field.key'))
                                    ->required()
                                    ->alphaDash()
                                    ->maxLength(255)
                                    ->disabled(fn (?LeadFieldDefinition $record): bool => (bool) $record?->values()->exists()),
                                Forms\Components\Select::make('type')
                                    ->label(__('lead-pipeline::lead-pipeline.field.type'))
                                    ->options(LeadFieldTypeEnum::class)
                                    ->required()
                                    ->reactive()
                                    ->disabled(fn (?LeadFieldDefinition $record): bool => (bool) $record?->values()->exists()),
                                Forms\Components\KeyValue::make('options')
                                    ->label(__('lead-pipeline::lead-pipeline.field.options'))
                                    ->visible(fn (Forms\Get $get): bool => in_array($get('type'), ['select', 'multi_select'])),
                                Forms\Components\Toggle::make('show_in_card')
                                    ->label(__('lead-pipeline::lead-pipeline.field.show_in_card')),
                            ])
                            ->orderColumn('sort')
                            ->reorderable()
                            ->addable()
                            ->deletable()
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(function (array $state): ?string {
                                $name = $state['name'] ?? null;

                                if (blank($name)) {
                                    return null;
                                }

                                $count = $state['values_count'] ?? null;

                                if (null === $count) {
                                    return $name;
                                }

                                return sprintf('%s — %s', $name, __('lead-pipeline::lead-pipeline.field.datapoints', [
                                    'count' => (int) $count,
                                ]));
                            })
                            ->extraItemActions([static::mergeFieldItemAction()])
                            ->defaultItems(0)
                            ->columns(2),
                    ]),

                Forms\Components\Section::make(__('lead-pipeline::lead-pipeline.board.sharing'))
                    ->visible(fn (?LeadBoard $record): bool => null !== $record)
                    ->schema([
                        Forms\Components\Select::make('shared_tenant_ids')
                            ->label(__('lead-pipeline::lead-pipeline.board.shared_boards'))
                            ->helperText(__('lead-pipeline::lead-pipeline.board.shared_boards_helper'))
                            ->options(fn (?LeadBoard $record): array => FilamentLeadPipelinePlugin::getShareableTenantOptions($record))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->dehydrated(false)
                            ->loadStateFromRelationshipsUsing(fn (Forms\Components\Select $component, LeadBoard $record): Forms\Components\Select => $component->state(
                                $record->sharedTenants()
                                    ->whereIn('shared_with_type', static::tenantShareTypes())
                                    ->pluck('shared_with_id')
                                    ->all(),
                            ))
                            ->saveRelationshipsUsing(fn (Forms\Components\Select $component, LeadBoard $record): bool => static::syncSharedTenants($record, $component->getState())),
                        Forms\Components\Select::make('shared_all_board_tenant_ids')
                            ->label(__('lead-pipeline::lead-pipeline.board.shared_all_boards'))
                            ->helperText(__('lead-pipeline::lead-pipeline.board.shared_all_boards_helper'))
                            ->options(fn (?LeadBoard $record): array => FilamentLeadPipelinePlugin::getShareableTenantOptions($record))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->dehydrated(false)
                            ->loadStateFromRelationshipsUsing(fn (Forms\Components\Select $component, LeadBoard $record): Forms\Components\Select => $component->state(
                                $record->explicitTeamShares()
                                    ->whereIn('shared_with_type', static::tenantShareTypes())
                                    ->pluck('shared_with_id')
                                    ->all(),
                            ))
                            ->saveRelationshipsUsing(fn (Forms\Components\Select $component, LeadBoard $record): bool => static::syncExplicitTeamShares($record, $component->getState())),
                        Forms\Components\CheckboxList::make('shared_tenant_relation_keys')
                            ->label(__('lead-pipeline::lead-pipeline.board.shared_relation_boards'))
                            ->helperText(__('lead-pipeline::lead-pipeline.board.shared_relation_boards_helper'))
                            ->options(fn (): array => FilamentLeadPipelinePlugin::getShareableTenantRelations())
                            ->visible(fn (): bool => [] !== FilamentLeadPipelinePlugin::getShareableTenantRelations())
                            ->dehydrated(false)
                            ->loadStateFromRelationshipsUsing(fn (Forms\Components\CheckboxList $component, LeadBoard $record): Forms\Components\CheckboxList => $component->state(
                                $record->relationTeamShares()
                                    ->pluck('shared_with_relation')
                                    ->all(),
                            ))
                            ->saveRelationshipsUsing(fn (Forms\Components\CheckboxList $component, LeadBoard $record): bool => static::syncRelationTeamShares($record, $component->getState()))
                            ->columns(2),
                    ])
                    ->columns(1),

                ...static::getBoardFormExtensionComponents(),
                ...static::getIntegrationBoardFormComponents($form->getRecord()),
            ]);
    }

    /**
     * @param  array<int|string, string>|null  $tenantIds
     */
    public static function syncSharedTenants(LeadBoard $board, ?array $tenantIds): bool
    {
        $tenantIds  = static::cleanShareState($tenantIds);
        $tenantType = static::tenantShareType();

        $board->sharedTenants()
            ->whereIn('shared_with_type', static::tenantShareTypes())
            ->delete();

        foreach ($tenantIds as $tenantId) {
            LeadBoardSharedTenant::query()->create([
                'lead_board_uuid'  => $board->getKey(),
                'shared_with_type' => $tenantType,
                'shared_with_id'   => $tenantId,
                'permissions'      => null,
            ]);
        }

        return true;
    }

    /**
     * @param  array<int|string, string>|null  $tenantIds
     */
    public static function syncExplicitTeamShares(LeadBoard $board, ?array $tenantIds): bool
    {
        $tenantIds  = static::cleanShareState($tenantIds);
        $tenantFk   = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $tenantType = static::tenantShareType();

        $board->explicitTeamShares()
            ->whereIn('shared_with_type', static::tenantShareTypes())
            ->delete();

        foreach ($tenantIds as $tenantId) {
            LeadBoardTeamShare::query()->create([
                'owner_team_id'        => $board->{$tenantFk},
                'shared_with_type'     => $tenantType,
                'shared_with_id'       => $tenantId,
                'shared_with_relation' => null,
                'permissions'          => null,
            ]);
        }

        return true;
    }

    /**
     * @param  array<int|string, string>|null  $relations
     */
    public static function syncRelationTeamShares(LeadBoard $board, ?array $relations): bool
    {
        $relations = static::cleanShareState($relations);
        $tenantFk  = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

        $board->relationTeamShares()->delete();

        foreach ($relations as $relation) {
            LeadBoardTeamShare::query()->create([
                'owner_team_id'        => $board->{$tenantFk},
                'shared_with_type'     => null,
                'shared_with_id'       => null,
                'shared_with_relation' => $relation,
                'permissions'          => null,
            ]);
        }

        return true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $query->withCount(['phases', 'leads', 'sources']);

                $userId = auth()->id();
                $userFk = config('lead-pipeline.user_foreign_key', 'user_uuid');
                $tenant = filament()->getTenant();

                $query->where(function ($q) use ($userId, $userFk, $tenant): void {
                    $q->whereHas('admins', fn ($aq) => $aq->where('lead_board_admins.' . $userFk, $userId))
                        ->orWhereHas('leads', fn ($lq) => $lq->where('assigned_to', $userId))
                        ->orWhere(fn ($sq) => $sq->sharedWithTenant($tenant));
                });
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('lead-pipeline::lead-pipeline.field.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('phases_count')
                    ->label(__('lead-pipeline::lead-pipeline.board.phases'))
                    ->counts('phases'),
                Tables\Columns\TextColumn::make('leads_count')
                    ->label(__('lead-pipeline::lead-pipeline.lead.plural'))
                    ->counts('leads'),
                Tables\Columns\TextColumn::make('sources_count')
                    ->label(__('lead-pipeline::lead-pipeline.source.plural'))
                    ->counts('sources'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('lead-pipeline::lead-pipeline.board.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('lead-pipeline::lead-pipeline.board.created_at'))
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('sort')
            ->recordUrl(fn (LeadBoard $record): string => KanbanBoard::getUrl(['board' => $record->getKey()]))
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('kanban')
                        ->label(__('lead-pipeline::lead-pipeline.board.open'))
                        ->icon('heroicon-o-view-columns')
                        ->url(fn (LeadBoard $record): string => KanbanBoard::getUrl(['board' => $record->getKey()])),
                    Tables\Actions\EditAction::make()
                        ->visible(fn (LeadBoard $record): bool => $record->isAdmin(auth()->user())),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (LeadBoard $record): bool => $record->isAdmin(auth()->user())),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('sources')
                    ->label(__('lead-pipeline::lead-pipeline.source.management'))
                    ->icon('heroicon-o-bolt')
                    ->url(SourceManagement::getUrl()),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LeadBoardResource\RelationManagers\ReportsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLeadBoards::route('/'),
            'create' => Pages\CreateLeadBoard::route('/create'),
            'edit'   => Pages\EditLeadBoard::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> Merge-Ziel-Optionen des Boards; Standard-Felder schreiben in die Lead-Spalte. */
    public static function mergeTargetOptionsFor(LeadBoard $board, ?string $excludeId = null): array
    {
        return $board
            ->fieldDefinitions()
            ->when($excludeId, fn (Builder $query) => $query->whereKeyNot($excludeId))
            ->orderByDesc('is_system')
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (LeadFieldDefinition $definition): array => [
                $definition->getKey() => $definition->is_system
                    ? sprintf('%s — %s', $definition->name, __('lead-pipeline::lead-pipeline.board_edit.merge_standard_field'))
                    : sprintf('%s (%s)', $definition->name, $definition->key),
            ])
            ->all();
    }

    /**
     * Gemeinsame Merge-Ausfuehrung fuer Header- und Repeater-Item-Action:
     * Service-Aufruf, Ergebnis-Notification und Redirect auf die Edit-Seite,
     * damit kein staler Repeater-State das Ergebnis wieder zerstoert.
     *
     * @param  array<string, mixed>  $valueMap
     */
    public static function executeFieldMerge(LeadBoard $board, string $sourceId, string $targetId, array $valueMap, mixed $livewire): void
    {
        $definitions = $board->fieldDefinitions();

        $source = (clone $definitions)->findOrFail($sourceId);
        $target = (clone $definitions)->findOrFail($targetId);

        try {
            $result = app(LeadFieldMergeService::class)->merge(
                $source,
                $target,
                array_filter($valueMap, fn ($value): bool => filled($value)),
                auth()->user(),
            );
        } catch (InvalidFieldMergeException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lead-pipeline::lead-pipeline.board_edit.merge_action_label'))
            ->body(__('lead-pipeline::lead-pipeline.board_edit.merge_success', [
                'moved'        => $result->moved,
                'deduplicated' => $result->deduplicated,
                'conflicts'    => $result->conflicts,
            ]))
            ->send();

        $livewire->redirect(static::getUrl('edit', ['record' => $board]));
    }

    /**
     * Item-Action am Feld-Repeater: ueberfuehrt die Datenpunkte des Felds
     * direkt in ein anderes Feld (oder ein Standard-Feld) via Merge-Service.
     */
    protected static function mergeFieldItemAction(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('mergeInto')
            ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_action_label'))
            ->icon('heroicon-o-arrows-pointing-in')
            ->modalDescription(__('lead-pipeline::lead-pipeline.board_edit.merge_description'))
            ->visible(fn (array $arguments): bool => str_starts_with((string) ($arguments['item'] ?? ''), 'record-'))
            ->form(function (array $arguments, Forms\Components\Repeater $component): array {
                $source = $component->getCachedExistingRecords()->get((string) ($arguments['item'] ?? ''));
                $board  = $component->getRecord();

                if ( ! $source instanceof LeadFieldDefinition || ! $board instanceof LeadBoard) {
                    return [];
                }

                return [
                    Forms\Components\Select::make('target')
                        ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_target_label'))
                        ->options(static::mergeTargetOptionsFor($board, $source->getKey()))
                        ->required(),
                    Forms\Components\KeyValue::make('value_map')
                        ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_value_map_label'))
                        ->helperText(__('lead-pipeline::lead-pipeline.board_edit.merge_value_map_help')),
                ];
            })
            ->action(function (array $arguments, array $data, Forms\Components\Repeater $component, $livewire): void {
                $source = $component->getCachedExistingRecords()->get((string) ($arguments['item'] ?? ''));
                $board  = $component->getRecord();

                if ( ! $source instanceof LeadFieldDefinition || ! $board instanceof LeadBoard) {
                    return;
                }

                static::executeFieldMerge($board, $source->getKey(), $data['target'], $data['value_map'] ?? [], $livewire);
            });
    }

    /** @return array<int, class-string> */
    /** @return array<string, string> Registrierte Converter des aktuellen Panels (Key => Anzeigename). */
    protected static function registeredConverterOptions(): array
    {
        $plugin = filament()->getCurrentPanel()?->getPlugin('filament-lead-pipeline');

        if ( ! $plugin instanceof FilamentLeadPipelinePlugin) {
            return [];
        }

        return collect($plugin->getConverters())
            ->mapWithKeys(fn (string $class, string $key): array => [
                (string) $key => app($class)->getDisplayName(),
            ])
            ->toArray();
    }

    /**
     * @param  array<int|string, string>|null  $state
     * @return array<int, string>
     */
    protected static function cleanShareState(?array $state): array
    {
        return collect($state ?? [])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    protected static function tenantShareType(): string
    {
        $tenantModel = config('lead-pipeline.tenancy.model');

        return (new $tenantModel())->getMorphClass();
    }

    /**
     * @return array<int, string>
     */
    protected static function tenantShareTypes(): array
    {
        $tenantModel = config('lead-pipeline.tenancy.model');

        return array_values(array_unique(array_filter([
            static::tenantShareType(),
            $tenantModel,
        ])));
    }
}
