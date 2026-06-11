<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaCreativesJob;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaInsightsJob;

class EditLeadReport extends EditRecord
{
    protected static string $resource = LeadReportResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['newPassword']);

        return $data;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('lead-pipeline::reports.actions.preview'))
                ->icon('heroicon-o-eye')
                ->url(fn (): string => route('lead-pipeline.reports.show', $this->record->share_token), shouldOpenInNewTab: true),

            Action::make('refresh')
                ->label(__('lead-pipeline::reports.actions.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $dispatched = false;

                    foreach ($this->record->adSources as $source) {
                        $lock = "lead-report-refresh:{$source->ad_account_id}";

                        if (Cache::has($lock)) {
                            continue;
                        }

                        Cache::put($lock, true, now()->addMinutes(15));
                        SyncMetaInsightsJob::dispatch($source->facebook_connection_uuid, $source->ad_account_id, $source->campaign_ids, 7);
                        SyncMetaCreativesJob::dispatch($source->facebook_connection_uuid, $source->ad_account_id, $source->campaign_ids);
                        $dispatched = true;
                    }

                    if ($dispatched) {
                        Notification::make()->success()
                            ->title(__('lead-pipeline::reports.actions.refresh_started'))->send();

                        return;
                    }

                    Notification::make()->warning()
                        ->title(__('lead-pipeline::reports.actions.refresh_throttled'))->send();
                }),

            Action::make('rotateToken')
                ->label(__('lead-pipeline::reports.actions.rotate_token'))
                ->icon('heroicon-o-key')
                ->authorize('share', $this->record)
                ->requiresConfirmation()
                ->action(fn () => $this->record->rotateToken()),

            Action::make('downloadPdf')
                ->label(__('lead-pipeline::reports.actions.download_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn (): string => route('lead-pipeline.reports.pdf', $this->record->share_token), shouldOpenInNewTab: true),
        ];
    }
}
