<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource;

class ListLeadReports extends ListRecords
{
    protected static string $resource = LeadReportResource::class;

    /** @return array<int, Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
