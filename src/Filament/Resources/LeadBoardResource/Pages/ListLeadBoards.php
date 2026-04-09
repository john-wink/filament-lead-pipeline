<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;

class ListLeadBoards extends ListRecords
{
    protected static string $resource = LeadBoardResource::class;

    public function getFooter(): ?View
    {
        return view('lead-pipeline::filament.components.analytics-modal-embed');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('analytics')
                ->label(__('lead-pipeline::lead-pipeline.analytics.title'))
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->action(fn () => $this->dispatch('open-analytics')),
            Actions\CreateAction::make(),
        ];
    }
}
