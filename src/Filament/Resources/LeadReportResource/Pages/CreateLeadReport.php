<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource;

class CreateLeadReport extends CreateRecord
{
    protected static string $resource = LeadReportResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $userFk = config('lead-pipeline.user_foreign_key', 'user_uuid');

        $data[$teamFk] ??= Filament::getTenant()?->getKey();
        $data[$userFk] ??= auth()->id();

        unset($data['newPassword']);

        return $data;
    }
}
