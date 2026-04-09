<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\LeadSourceManager;
use Throwable;

class SourceManagement extends Page implements HasTable
{
    use InteractsWithTable;

    public ?string $editingFunnelSourceId = null;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static string $view = 'lead-pipeline::filament.pages.source-management';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('lead-pipeline::lead-pipeline.source.management');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LeadSource::query()
                    ->with('funnel')
                    ->when(
                        filament()->getTenant(),
                        fn ($q) => $q->where(config('lead-pipeline.tenancy.foreign_key'), filament()->getTenant()->getKey())
                    )
            )
            ->modifyQueryUsing(fn ($query) => $query->withCount('leads'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('lead-pipeline::lead-pipeline.field.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('driver')
                    ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('lead-pipeline::lead-pipeline.field.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('board.name')
                    ->label(__('lead-pipeline::lead-pipeline.board.singular')),
                Tables\Columns\TextColumn::make('leads_count')
                    ->label(__('lead-pipeline::lead-pipeline.lead.plural'))
                    ->counts('leads'),
                Tables\Columns\TextColumn::make('last_received_at')
                    ->label(__('lead-pipeline::lead-pipeline.source.last_received'))
                    ->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form(function (LeadSource $record): array {
                        $baseFields = [
                            Forms\Components\TextInput::make('name')
                                ->label(__('lead-pipeline::lead-pipeline.field.name'))
                                ->required()
                                ->maxLength(255),
                            Forms\Components\Select::make('driver')
                                ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                                ->options(LeadSourceTypeEnum::class)
                                ->disabled(),
                            Forms\Components\Select::make('status')
                                ->label(__('lead-pipeline::lead-pipeline.field.status'))
                                ->options(LeadSourceStatusEnum::class),
                            Forms\Components\Select::make(LeadSource::fkColumn('lead_board'))
                                ->label(__('lead-pipeline::lead-pipeline.board.singular'))
                                ->options(LeadBoard::query()->pluck('name', LeadBoard::pkColumn()))
                                ->required(),
                        ];

                        $driverFields = $this->getDriverConfigFields($record->driver);

                        return [...$baseFields, ...$driverFields];
                    }),
                ...$this->getDriverTableActions(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('lead-pipeline::lead-pipeline.source.create'))
                    ->model(LeadSource::class)
                    ->form(fn () => [
                        Forms\Components\TextInput::make('name')
                            ->label(__('lead-pipeline::lead-pipeline.field.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('driver')
                            ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                            ->options(LeadSourceTypeEnum::class)
                            ->required()
                            ->live(),
                        Forms\Components\Select::make(LeadSource::fkColumn('lead_board'))
                            ->label(__('lead-pipeline::lead-pipeline.board.singular'))
                            ->options(LeadBoard::query()->pluck('name', LeadBoard::pkColumn()))
                            ->required(),
                        Forms\Components\Section::make(__('lead-pipeline::lead-pipeline.source.connection'))
                            ->schema(fn (callable $get): array => $this->getDriverConfigFields($get('driver')))
                            ->visible(fn (callable $get) => filled($get('driver')) && 'manual' !== $get('driver')),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        if (filament()->getTenant()) {
                            $data[config('lead-pipeline.tenancy.foreign_key')] = filament()->getTenant()->getKey();
                        }

                        return $data;
                    })
                    ->after(function (LeadSource $record): void {
                        if ('funnel' === $record->driver && ! $record->funnel) {
                            $boardFk = LeadBoard::fkColumn('lead_board');
                            $record->funnel()->create([
                                $boardFk            => $record->{$boardFk},
                                'name'              => $record->name,
                                'slug'              => \Illuminate\Support\Str::slug($record->name),
                                'design'            => static::resolvePanelDesignForNewFunnel(),
                                'is_active'         => true,
                                'views_count'       => 0,
                                'submissions_count' => 0,
                            ]);
                        }

                        // Meta: Subscribe page to leadgen webhook
                        if ('meta' === $record->driver && $record->facebookPage) {
                            $page = $record->facebookPage;
                            if ( ! $page->is_webhooks_subscribed) {
                                try {
                                    $facebook = app(\JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class);
                                    $facebook->subscribePageToLeadgen($page->page_id, $page->page_access_token);
                                    $page->update(['is_webhooks_subscribed' => true]);
                                } catch (Throwable) {
                                    // Webhook subscription can be retried later
                                }
                            }
                        }
                    }),
            ]);
    }

    #[\Livewire\Attributes\On('funnel-saved')]
    public function closeFunnelBuilder(): void
    {
        $this->editingFunnelSourceId = null;
    }

    /**
     * Builds initial design values for a new funnel from the current Filament panel.
     *
     * @return array<string, mixed>
     */
    protected static function resolvePanelDesignForNewFunnel(): array
    {
        $design = [];

        try {
            $panel = filament()->getCurrentPanel();
            if ( ! $panel) {
                return $design;
            }

            $logo = $panel->getBrandLogo();
            if (is_string($logo) && filled($logo)) {
                $design['logo_url'] = $logo;
            }

            $favicon = $panel->getFavicon();
            if (filled($favicon)) {
                $design['favicon_url'] = $favicon;
            }

            $colors = $panel->getColors();
            if (isset($colors['primary'])) {
                $primary = $colors['primary'];
                if (is_string($primary)) {
                    $design['primary_color'] = $primary;
                } elseif (is_array($primary) && isset($primary[500])) {
                    $design['primary_color'] = $primary[500];
                }
            }
        } catch (Throwable) {
            // Graceful fallback
        }

        return $design;
    }

    /** @return array<Tables\Actions\Action> */
    protected function getDriverTableActions(): array
    {
        $manager = app(LeadSourceManager::class);
        $page    = $this;
        $actions = [];

        foreach ($manager->getAvailableDrivers() as $driverName => $class) {
            try {
                $driver = $manager->getDriver($driverName);

                foreach ($driver->getTableActions(new LeadSource()) as $action) {
                    // Prefix action name with driver to avoid collisions
                    $action->name("{$driverName}_{$action->getName()}");

                    // Only show for matching driver
                    $action->visible(fn (LeadSource $record) => $record->driver === $driverName);

                    // Funnel edit needs special handling
                    if (str_ends_with($action->getName(), '_edit_funnel')) {
                        $action->action(fn (LeadSource $record) => $page->editingFunnelSourceId = $record->getKey());
                    }

                    $actions[] = $action;
                }
            } catch (Throwable) {
                // Skip drivers that fail
            }
        }

        return $actions;
    }

    /** @return array<Forms\Components\Component> */
    protected function getDriverConfigFields(?string $driver): array
    {
        if ( ! $driver) {
            return [];
        }

        try {
            $manager        = app(LeadSourceManager::class);
            $driverInstance = $manager->getDriver($driver);

            return $driverInstance->getConfigFormSchema();
        } catch (Throwable) {
            return [];
        }
    }
}
