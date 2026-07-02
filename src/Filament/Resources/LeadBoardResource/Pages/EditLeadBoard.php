<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;

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

    /** @return array<string, string> Nicht-System-Felddefinitionen des Boards (uuid => Label) */
    public function mergeableFieldOptions(): array
    {
        return $this->getRecord()
            ->fieldDefinitions()
            ->where('is_system', false)
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (LeadFieldDefinition $definition): array => [
                $definition->getKey() => sprintf('%s (%s)', $definition->name, $definition->key),
            ])
            ->all();
    }

    /** @return array<string, string> Alle Felddefinitionen als Merge-Ziel; Standard-Felder schreiben in die Lead-Spalte */
    public function mergeTargetOptions(): array
    {
        return LeadBoardResource::mergeTargetOptionsFor($this->getRecord());
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
            $this->mergeFieldsAction(),
            Actions\DeleteAction::make(),
        ];
    }

    private function mergeFieldsAction(): Actions\Action
    {
        return Actions\Action::make('mergeFields')
            ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_action_label'))
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('gray')
            ->modalDescription(__('lead-pipeline::lead-pipeline.board_edit.merge_description'))
            ->form([
                Forms\Components\Select::make('source')
                    ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_source_label'))
                    ->options(fn (): array => $this->mergeableFieldOptions())
                    ->required()
                    ->different('target'),
                Forms\Components\Select::make('target')
                    ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_target_label'))
                    ->options(fn (): array => $this->mergeTargetOptions())
                    ->required()
                    ->different('source'),
                Forms\Components\KeyValue::make('value_map')
                    ->label(__('lead-pipeline::lead-pipeline.board_edit.merge_value_map_label'))
                    ->helperText(__('lead-pipeline::lead-pipeline.board_edit.merge_value_map_help')),
            ])
            ->action(function (array $data): void {
                LeadBoardResource::executeFieldMerge(
                    $this->getRecord(),
                    $data['source'],
                    $data['target'],
                    $data['value_map'] ?? [],
                    $this,
                );
            });
    }
}
