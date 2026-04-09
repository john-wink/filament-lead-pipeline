<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;

class EditLeadBoard extends EditRecord
{
    protected static string $resource = LeadBoardResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['phases'], $data['fieldDefinitions']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
