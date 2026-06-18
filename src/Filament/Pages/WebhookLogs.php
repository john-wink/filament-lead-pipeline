<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

class WebhookLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string $view = 'lead-pipeline::filament.pages.webhook-logs';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('lead-pipeline::lead-pipeline.webhook_log.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LeadWebhookLog::query()
                    ->when(
                        filament()->getTenant(),
                        fn ($q) => $q->forTeam(filament()->getTenant()->getKey()),
                    )
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.received_at'))
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.event_type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('driver')
                    ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('outcome')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.outcome'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created', 'subscribed', 'verified', 'ok' => 'success',
                        'skipped'                                  => 'gray',
                        'rejected_signature', 'subscribe_failed', 'verify_failed', 'source_inactive', 'no_phase', 'processing_error', 'error' => 'danger',
                        default                                    => 'warning',
                    }),
                Tables\Columns\TextColumn::make('http_status')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.http_status'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('page_id')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.page_id'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.message'))
                    ->limit(60)
                    ->tooltip(fn (LeadWebhookLog $record): ?string => $record->message)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.event_type'))
                    ->options(WebhookLogEventType::class),
                Tables\Filters\SelectFilter::make('driver')
                    ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                    ->options(['api' => 'API', 'zapier' => 'Zapier', 'meta' => 'Meta', 'funnel' => 'Funnel']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.details'))
                    ->modalHeading(__('lead-pipeline::lead-pipeline.webhook_log.details'))
                    ->infolist([
                        TextEntry::make('outcome')->badge(),
                        TextEntry::make('message')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('request')
                            ->label('Request')
                            ->columnSpanFull()
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—'),
                        TextEntry::make('response')
                            ->label('Response')
                            ->columnSpanFull()
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—'),
                    ]),
            ]);
    }
}
