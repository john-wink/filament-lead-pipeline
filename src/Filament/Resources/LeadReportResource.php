<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources;

use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use JohnWink\FilamentLeadPipeline\Contracts\ResolvesReportBranding;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Enums\ReportSectionEnum;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportSend;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Support\QrCodeSvg;
use Throwable;

class LeadReportResource extends Resource
{
    protected static ?string $model = LeadReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    /** Kein eigenes Nav-Item — Reports sind über das Board (RelationManager) erreichbar. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('lead-pipeline::reports.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lead-pipeline::reports.resource.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('lead-pipeline.navigation.group');
    }

    public static function getNavigationSort(): ?int
    {
        return ((int) config('lead-pipeline.navigation.sort', 10)) + 1;
    }

    public static function getEloquentQuery(): Builder
    {
        return LeadReport::query()->forTeam(filament()->getTenant()?->getKey());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('report')->columnSpanFull()->tabs([
                Forms\Components\Tabs\Tab::make(__('lead-pipeline::reports.resource.tabs.content'))->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\Select::make('date_preset_default')
                        ->label(__('lead-pipeline::reports.resource.fields.date_preset_default'))
                        ->options(collect(ReportDatePresetEnum::cases())
                            ->reject(fn (ReportDatePresetEnum $preset): bool => ReportDatePresetEnum::Custom === $preset)
                            ->mapWithKeys(fn (ReportDatePresetEnum $preset): array => [$preset->value => $preset->label()]))
                        ->default(ReportDatePresetEnum::Last30Days->value)
                        ->required(),
                    Forms\Components\Toggle::make('date_locked')
                        ->label(__('lead-pipeline::reports.resource.fields.date_locked')),
                    Forms\Components\CheckboxList::make('sections')
                        ->label(__('lead-pipeline::reports.resource.fields.sections'))
                        ->options(collect(ReportSectionEnum::cases())
                            ->mapWithKeys(fn (ReportSectionEnum $section): array => [$section->value => __('lead-pipeline::reports.sections.' . $section->value)]))
                        ->default(ReportSectionEnum::defaults())
                        ->columns(3),
                    Forms\Components\Fieldset::make(__('lead-pipeline::reports.resource.fields.funnel_mapping'))->schema([
                        Forms\Components\Select::make('funnel_mapping.qualified')
                            ->label(__('lead-pipeline::reports.funnel.qualified'))
                            ->multiple()
                            ->options(self::phaseTypeOptions()),
                        Forms\Components\Select::make('funnel_mapping.won')
                            ->label(__('lead-pipeline::reports.funnel.won'))
                            ->multiple()
                            ->options(self::phaseTypeOptions()),
                    ]),
                    Forms\Components\Select::make('boards')
                        ->label(__('lead-pipeline::reports.resource.fields.boards'))
                        ->relationship('boards', 'name', fn (Builder $query): Builder => $query->visibleToTenant(Filament::getTenant()))
                        ->multiple()
                        ->preload()
                        ->rule(self::boardsBelongToTenantRule()),
                    Forms\Components\Repeater::make('adSources')
                        ->label(__('lead-pipeline::reports.resource.fields.ad_sources'))
                        ->relationship('adSources')
                        ->defaultItems(0)
                        ->schema([
                            Forms\Components\Select::make('facebook_connection_uuid')
                                ->label(__('lead-pipeline::reports.resource.fields.connection'))
                                ->options(fn (): array => FacebookConnection::query()
                                    ->where(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'), Filament::getTenant()?->getKey())
                                    ->where('status', 'connected')
                                    ->pluck('facebook_user_name', 'uuid')
                                    ->all())
                                ->helperText(fn (?string $state): ?HtmlString => self::missingAdsReadHint($state))
                                ->live()
                                ->required()
                                ->rule(self::connectionBelongsToTenantRule()),
                            Forms\Components\Select::make('ad_account_id')
                                ->label(__('lead-pipeline::reports.resource.fields.ad_account'))
                                ->options(fn (Forms\Get $get): array => self::adAccountOptions($get('facebook_connection_uuid')))
                                ->searchable()
                                ->required(),
                            Forms\Components\Select::make('campaign_ids')
                                ->label(__('lead-pipeline::reports.resource.fields.campaigns'))
                                ->helperText(__('lead-pipeline::reports.resource.fields.campaigns_hint'))
                                ->multiple()
                                ->options(fn (Forms\Get $get): array => self::campaignOptions($get('facebook_connection_uuid'), $get('ad_account_id'))),
                        ]),
                ]),

                Forms\Components\Tabs\Tab::make(__('lead-pipeline::reports.resource.tabs.branding'))->schema([
                    Forms\Components\FileUpload::make('branding_settings.logo_path')
                        ->label(__('lead-pipeline::reports.resource.fields.logo'))
                        ->disk(config('lead-pipeline.reports.media_disk'))
                        ->directory('lead-reports/branding')
                        ->image(),
                    Forms\Components\FileUpload::make('branding_settings.co_logo_path')
                        ->label(__('lead-pipeline::reports.resource.fields.co_logo'))
                        ->disk(config('lead-pipeline.reports.media_disk'))
                        ->directory('lead-reports/branding')
                        ->image(),
                    Forms\Components\ColorPicker::make('branding_settings.accent_color')
                        ->label(__('lead-pipeline::reports.resource.fields.accent_color')),
                    Forms\Components\RichEditor::make('branding_settings.claim_html')
                        ->label(__('lead-pipeline::reports.resource.fields.claim'))
                        ->toolbarButtons(['bold', 'italic', 'h3', 'bulletList'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => null === $state ? null : strip_tags($state, '<h3><p><strong><em><br><ul><li>')),
                    Forms\Components\Textarea::make('branding_settings.footer_text')
                        ->label(__('lead-pipeline::reports.resource.fields.footer_text')),
                    Forms\Components\Placeholder::make('branding_effective')
                        ->label(__('lead-pipeline::reports.resource.fields.branding_effective'))
                        ->content(fn (?LeadReport $record): string => null === $record
                            ? '–'
                            : __('lead-pipeline::reports.resource.fields.branding_inherited', [
                                'color' => app(ResolvesReportBranding::class)->resolve($record)->accentColor,
                            ])),
                ]),

                Forms\Components\Tabs\Tab::make(__('lead-pipeline::reports.resource.tabs.sharing'))
                    ->visible(fn (?LeadReport $record): bool => null === $record || (auth()->user()?->can('share', $record) ?? false))
                    ->schema([
                        Forms\Components\Placeholder::make('share_url')
                            ->label(__('lead-pipeline::reports.resource.fields.share_url'))
                            ->content(fn (?LeadReport $record): HtmlString => new HtmlString(
                                null === $record
                                    ? '–'
                                    : '<code>' . e(route('lead-pipeline.reports.show', $record->share_token)) . '</code>',
                            )),
                        Forms\Components\Placeholder::make('share_qr')
                            ->label('QR')
                            ->content(fn (?LeadReport $record): HtmlString => new HtmlString(
                                null === $record ? '–' : QrCodeSvg::make(route('lead-pipeline.reports.show', $record->share_token)),
                            )),
                        Forms\Components\TextInput::make('newPassword')
                            ->label(__('lead-pipeline::reports.resource.fields.password'))
                            ->password()
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, ?LeadReport $record): void {
                                if (null !== $record && null !== $state && '' !== $state) {
                                    $record->update(['password' => $state]);
                                }
                            }),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label(__('lead-pipeline::reports.resource.fields.expires_at')),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('lead-pipeline::reports.resource.fields.is_active'))
                            ->default(true),
                        Forms\Components\Placeholder::make('view_stats')
                            ->label(__('lead-pipeline::reports.resource.fields.view_stats'))
                            ->content(fn (?LeadReport $record): HtmlString => self::viewStats($record)),
                    ]),

                Forms\Components\Tabs\Tab::make(__('lead-pipeline::reports.resource.tabs.scheduling'))->schema([
                    Forms\Components\Repeater::make('schedules')
                        ->label(__('lead-pipeline::reports.resource.fields.schedules'))
                        ->relationship('schedules')
                        ->defaultItems(0)
                        ->schema([
                            Forms\Components\Select::make('frequency')
                                ->options([
                                    'weekly'  => __('lead-pipeline::reports.resource.fields.weekly'),
                                    'monthly' => __('lead-pipeline::reports.resource.fields.monthly'),
                                ])
                                ->default('weekly')
                                ->live()
                                ->required(),
                            Forms\Components\Select::make('weekday')
                                ->visible(fn (Forms\Get $get): bool => 'weekly' === $get('frequency'))
                                ->options(self::weekdayOptions()),
                            Forms\Components\Select::make('day_of_month')
                                ->visible(fn (Forms\Get $get): bool => 'monthly' === $get('frequency'))
                                ->options(array_combine(range(1, 28), range(1, 28))),
                            Forms\Components\TimePicker::make('send_time')->seconds(false)->default('08:00'),
                            Forms\Components\TagsInput::make('recipients')
                                ->rules(['array'])
                                ->nestedRecursiveRules(['email']),
                            Forms\Components\Toggle::make('attach_pdf')->default(true),
                            Forms\Components\Toggle::make('is_active')->default(true),
                        ]),
                    Forms\Components\Placeholder::make('send_log')
                        ->label(__('lead-pipeline::reports.resource.fields.send_log'))
                        ->content(fn (?LeadReport $record): HtmlString => self::sendLog($record)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('share_token')
                    ->label(__('lead-pipeline::reports.resource.fields.share_url'))
                    ->formatStateUsing(fn (): string => __('lead-pipeline::reports.resource.fields.copy_link'))
                    ->copyable()
                    ->copyableState(fn (LeadReport $record): string => route('lead-pipeline.reports.show', $record->share_token)),
                Tables\Columns\IconColumn::make('stale')
                    ->label(__('lead-pipeline::reports.resource.fields.sync_state'))
                    ->state(fn (LeadReport $record): bool => self::isStale($record))
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success')
                    ->tooltip(fn (LeadReport $record): ?string => self::isStale($record) ? __('lead-pipeline::reports.resource.fields.stale_hint') : null),
                Tables\Columns\TextColumn::make('views_count')->sortable(),
                Tables\Columns\TextColumn::make('last_viewed_at')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\ToggleColumn::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLeadReports::route('/'),
            'create' => Pages\CreateLeadReport::route('/create'),
            'edit'   => Pages\EditLeadReport::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function phaseTypeOptions(): array
    {
        return collect(LeadPhaseTypeEnum::cases())
            ->mapWithKeys(fn (LeadPhaseTypeEnum $type): array => [$type->value => $type->value])
            ->all();
    }

    /** @return array<int, string> */
    private static function weekdayOptions(): array
    {
        return collect(range(1, 7))
            ->mapWithKeys(fn (int $day): array => [$day => now()->startOfWeek()->addDays($day - 1)->translatedFormat('l')])
            ->all();
    }

    private static function missingAdsReadHint(?string $connectionUuid): ?HtmlString
    {
        if (null === $connectionUuid) {
            return null;
        }

        $connection = FacebookConnection::query()->find($connectionUuid);
        $scopes     = (array) ($connection?->scopes ?? []);

        if (null === $connection || in_array('ads_read', $scopes, true)) {
            return null;
        }

        return new HtmlString(
            e(__('lead-pipeline::reports.resource.fields.missing_ads_read'))
            . ' <a href="' . e(route('lead-pipeline.facebook.redirect')) . '" class="underline">'
            . e(__('lead-pipeline::reports.resource.fields.reconnect')) . '</a>',
        );
    }

    /** @return array<string, string> */
    private static function adAccountOptions(?string $connectionUuid): array
    {
        $connection = null === $connectionUuid ? null : FacebookConnection::query()->find($connectionUuid);

        if (null === $connection) {
            return [];
        }

        try {
            return Cache::remember(
                "lead-report-adaccounts:{$connection->uuid}",
                now()->addHour(),
                fn (): array => collect(app(FacebookGraphService::class)->getAdAccounts($connection->access_token))
                    ->mapWithKeys(fn (array $account): array => [(string) $account['id'] => "{$account['name']} ({$account['id']})"])
                    ->all(),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private static function campaignOptions(?string $connectionUuid, ?string $adAccountId): array
    {
        $connection = null === $connectionUuid ? null : FacebookConnection::query()->find($connectionUuid);

        if (null === $connection || null === $adAccountId || '' === $adAccountId) {
            return [];
        }

        try {
            return Cache::remember(
                "lead-report-campaigns:{$adAccountId}",
                now()->addHour(),
                fn (): array => app(FacebookGraphService::class)->getCampaigns($adAccountId, $connection->access_token),
            );
        } catch (Throwable) {
            return [];
        }
    }

    private static function connectionBelongsToTenantRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $belongs = FacebookConnection::query()
                ->whereKey($value)
                ->where(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'), Filament::getTenant()?->getKey())
                ->exists();

            if ( ! $belongs) {
                $fail(__('lead-pipeline::reports.resource.validation.foreign_connection'));
            }
        };
    }

    private static function boardsBelongToTenantRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            foreach ((array) $value as $boardKey) {
                $visible = LeadBoard::query()
                    ->whereKey($boardKey)
                    ->visibleToTenant(Filament::getTenant())
                    ->exists();

                if ( ! $visible) {
                    $fail(__('lead-pipeline::reports.resource.validation.foreign_board'));

                    return;
                }
            }
        };
    }

    private static function isStale(LeadReport $record): bool
    {
        $sources = $record->adSources;

        if ($sources->isEmpty()) {
            return false;
        }

        $lastSynced = $sources->max('last_synced_at');

        return $sources->contains(fn ($source): bool => 'failed' === $source->sync_status)
            || null === $lastSynced
            || $lastSynced->lt(now()->subDay());
    }

    private static function viewStats(?LeadReport $record): HtmlString
    {
        if (null === $record) {
            return new HtmlString('–');
        }

        $series = $record->viewAggregates()
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn ($aggregate): array => ['date' => $aggregate->date->toDateString(), 'value' => (int) $aggregate->views])
            ->all();

        $sparkline = [] === $series ? '' : view('lead-pipeline::reports.charts.area', [
            'series' => $series,
            'color'  => '#0f766e',
        ])->render();

        $lastViewed = $record->last_viewed_at?->format('d.m.Y H:i') ?? '–';

        return new HtmlString(
            '<div class="space-y-2"><p>' . e("{$record->views_count} Views · {$lastViewed}") . '</p>'
            . '<div class="max-w-xs">' . $sparkline . '</div></div>',
        );
    }

    private static function sendLog(?LeadReport $record): HtmlString
    {
        if (null === $record) {
            return new HtmlString('–');
        }

        $sends = LeadReportSend::query()
            ->whereIn('schedule_uuid', $record->schedules()->pluck('uuid'))
            ->orderByDesc('sent_at')
            ->limit(10)
            ->get();

        if ($sends->isEmpty()) {
            return new HtmlString('–');
        }

        $rows = $sends->map(fn (LeadReportSend $send): string => '<li>'
            . e($send->sent_at->format('d.m.Y H:i') . ' → ' . implode(', ', (array) $send->recipients)
                . ($send->pdf_attached ? ' (PDF)' : '') . ' · ' . $send->status)
            . '</li>')->implode('');

        return new HtmlString("<ul class=\"list-disc space-y-1 pl-4 text-sm\">{$rows}</ul>");
    }
}
