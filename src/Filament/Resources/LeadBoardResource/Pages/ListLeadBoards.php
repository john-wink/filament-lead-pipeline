<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;

use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Filament\Pages\IntegrationsPage;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;

class ListLeadBoards extends ListRecords
{
    protected static string $resource = LeadBoardResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('lead-pipeline::lead-pipeline.board.tab_active'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->active())
                ->badge(fn (): int => $this->visibleBoards()->active()->count()),
            'inactive' => Tab::make(__('lead-pipeline::lead-pipeline.board.tab_inactive'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->inactive())
                ->badge(fn (): int => $this->visibleBoards()->inactive()->count()),
        ];
    }

    public function getFooter(): ?View
    {
        return view('lead-pipeline::filament.components.analytics-modal-embed');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('integrations')
                ->label(__('lead-pipeline::lead-pipeline.integrations.title'))
                ->icon('heroicon-o-puzzle-piece')
                ->color('gray')
                ->url(fn (): string => IntegrationsPage::getUrl())
                ->visible(fn (): bool => filled(FilamentLeadPipelinePlugin::get()->getIntegrations())),
            Actions\Action::make('analytics')
                ->label(__('lead-pipeline::lead-pipeline.analytics.title'))
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->action(fn () => $this->dispatch('open-analytics')),
            Actions\CreateAction::make(),
        ];
    }

    protected function visibleBoards(): Builder
    {
        return LeadBoardResource::getEloquentQuery()
            ->visibleToUser(auth()->user(), filament()->getTenant());
    }
}
