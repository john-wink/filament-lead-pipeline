<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

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
                                        if ($state && in_array($state, ['won', 'lost'])) {
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
                                    ->options(function (): array {
                                        $plugin = filament()->getCurrentPanel()?->getPlugin('filament-lead-pipeline');

                                        if ( ! $plugin instanceof FilamentLeadPipelinePlugin) {
                                            return [];
                                        }

                                        return collect($plugin->getConverters())
                                            ->mapWithKeys(fn (string $class, string $key) => [
                                                $key => app($class)->getDisplayName(),
                                            ])
                                            ->toArray();
                                    })
                                    ->placeholder(__('lead-pipeline::lead-pipeline.phase.converter_select')),
                            ])
                            ->orderColumn('sort')
                            ->reorderable(fn (?LeadBoard $record): bool => ! $record?->hasLeads())
                            ->addable(fn (?LeadBoard $record): bool => ! $record?->hasLeads())
                            ->deletable(fn (?LeadBoard $record): bool => ! $record?->hasLeads())
                            ->disabled(fn (?LeadBoard $record): bool => (bool) $record?->hasLeads())
                            ->collapsible()
                            ->defaultItems(0)
                            ->columns(2),
                    ]),

                Forms\Components\Section::make(__('lead-pipeline::lead-pipeline.board.custom_fields'))
                    ->schema([
                        Forms\Components\Repeater::make('fieldDefinitions')
                            ->label(__('lead-pipeline::lead-pipeline.board.custom_fields'))
                            ->hiddenLabel()
                            ->relationship('fieldDefinitions', fn ($query) => $query->where('is_system', false))
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('lead-pipeline::lead-pipeline.field.name'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('key')
                                    ->label(__('lead-pipeline::lead-pipeline.field.key'))
                                    ->required()
                                    ->alphaDash()
                                    ->maxLength(255),
                                Forms\Components\Select::make('type')
                                    ->label(__('lead-pipeline::lead-pipeline.field.type'))
                                    ->options(LeadFieldTypeEnum::class)
                                    ->reactive(),
                                Forms\Components\KeyValue::make('options')
                                    ->label(__('lead-pipeline::lead-pipeline.field.options'))
                                    ->visible(fn (Forms\Get $get): bool => in_array($get('type'), ['select', 'multi_select'])),
                                Forms\Components\Toggle::make('is_required')
                                    ->label(__('lead-pipeline::lead-pipeline.field.required')),
                                Forms\Components\Toggle::make('show_in_card')
                                    ->label(__('lead-pipeline::lead-pipeline.field.show_in_card')),
                                Forms\Components\Toggle::make('show_in_funnel')
                                    ->label(__('lead-pipeline::lead-pipeline.field.show_in_funnel'))
                                    ->default(true),
                            ])
                            ->orderColumn('sort')
                            ->reorderable(fn (?LeadBoard $record): bool => ! $record?->hasLeads())
                            ->addable(fn (?LeadBoard $record): bool => ! $record?->hasLeads())
                            ->deletable(fn (?LeadBoard $record): bool => ! $record?->hasLeads())
                            ->disabled(fn (?LeadBoard $record): bool => (bool) $record?->hasLeads())
                            ->collapsible()
                            ->defaultItems(0)
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['phases', 'leads', 'sources']))
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
            ->actions([
                Tables\Actions\Action::make('kanban')
                    ->label(__('lead-pipeline::lead-pipeline.board.open'))
                    ->tooltip(__('lead-pipeline::lead-pipeline.board.open'))
                    ->icon('heroicon-o-view-columns')
                    ->url(fn (LeadBoard $record): string => KanbanBoard::getUrl(['board' => $record->getKey()])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLeadBoards::route('/'),
            'create' => Pages\CreateLeadBoard::route('/create'),
            'edit'   => Pages\EditLeadBoard::route('/{record}/edit'),
        ];
    }
}
