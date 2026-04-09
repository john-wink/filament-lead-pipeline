<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Services\LeadConversionService;
use Throwable;

class ConvertLeadAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('lead-pipeline::lead-pipeline.actions.convert'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('lead-pipeline::lead-pipeline.actions.convert_heading'))
            ->modalDescription(__('lead-pipeline::lead-pipeline.actions.convert_desc'))
            ->form(function (): array {
                $service    = app(LeadConversionService::class);
                $converters = $service->getAvailableConverters();

                $options = collect($converters)->mapWithKeys(
                    fn ($converter, $key) => [$key => $converter->getDisplayName()]
                )->toArray();

                return [
                    Forms\Components\Select::make('converter')
                        ->label(__('lead-pipeline::lead-pipeline.actions.convert_to'))
                        ->options($options)
                        ->required()
                        ->reactive(),
                ];
            })
            ->action(function (Lead $record, array $data): void {
                try {
                    $service = app(LeadConversionService::class);
                    $service->convert($record, $data['converter'], $data);

                    Notification::make()
                        ->success()
                        ->title(__('lead-pipeline::lead-pipeline.actions.converted_success'))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('lead-pipeline::lead-pipeline.actions.conversion_failed'))
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'convertLead';
    }
}
