<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

class EditLeadBoard extends EditRecord
{
    protected static string $resource = LeadBoardResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        if ( ! $record instanceof LeadBoard) {
            $record = LeadBoard::find($record);
        }

        if ( ! $record || ! auth()->user()) {
            return false;
        }

        return $record->isAdmin(auth()->user());
    }

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
