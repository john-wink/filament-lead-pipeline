<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lead-pipeline::reports.resource.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('share_token')
                    ->label(__('lead-pipeline::reports.resource.fields.share_url'))
                    ->formatStateUsing(fn (): string => __('lead-pipeline::reports.resource.fields.copy_link'))
                    ->copyable()
                    ->copyableState(fn (LeadReport $record): string => route('lead-pipeline.reports.show', $record->share_token)),
                Tables\Columns\TextColumn::make('views_count')->sortable(),
                Tables\Columns\TextColumn::make('last_viewed_at')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\ToggleColumn::make('is_active'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('createReport')
                    ->label(__('lead-pipeline::reports.resource.create_report'))
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => LeadReportResource::getUrl('create', ['board' => $this->getOwnerRecord()->getKey()])),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label(__('filament-actions::edit.single.label'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (LeadReport $record): string => LeadReportResource::getUrl('edit', ['record' => $record])),
            ])
            ->recordUrl(fn (LeadReport $record): string => LeadReportResource::getUrl('edit', ['record' => $record]));
    }
}
