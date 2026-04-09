<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Drivers;

use Filament\Tables\Actions\Action;
use JohnWink\FilamentLeadPipeline\Contracts\LeadSourceDriver;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class FunnelDriver implements LeadSourceDriver
{
    public function getDisplayName(): string
    {
        return 'Funnel';
    }

    public function validateConfig(array $config): bool
    {
        return true;
    }

    public function processWebhook(WebhookPayloadData $payload, LeadSource $source): LeadData
    {
        $fieldMapping = $this->getDefaultFieldMapping();
        $rawPayload   = $payload->raw_payload;

        return new LeadData(
            name: (string) ($rawPayload[$fieldMapping['name'] ?? 'name'] ?? ''),
            email: isset($rawPayload[$fieldMapping['email'] ?? 'email'])
                ? (string) $rawPayload[$fieldMapping['email'] ?? 'email']
                : null,
            phone: isset($rawPayload[$fieldMapping['phone'] ?? 'phone'])
                ? (string) $rawPayload[$fieldMapping['phone'] ?? 'phone']
                : null,
            custom_fields: array_diff_key($rawPayload, array_flip(array_values($fieldMapping))),
            source_driver: 'funnel',
            source_identifier: (string) $source->getKey(),
            value: isset($rawPayload['value']) ? (float) $rawPayload['value'] : null,
        );
    }

    public function verifySignature(string $payload, string $signature, LeadSource $source): bool
    {
        return true;
    }

    /** @return array<\Filament\Forms\Components\Component> */
    public function getConfigFormSchema(): array
    {
        return [];
    }

    public function getWebhookUrl(LeadSource $source): string
    {
        $funnel = $source->funnel;

        if ($funnel) {
            return $funnel->getPublicUrl();
        }

        $prefix = config('lead-pipeline.funnel.route_prefix', 'funnel');

        return \JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::publicUrl($prefix);
    }

    /** @return array<string, string> */
    public function getDefaultFieldMapping(): array
    {
        return [
            'name'  => 'name',
            'email' => 'email',
            'phone' => 'phone',
        ];
    }

    /** @return array<Action> */
    public function getTableActions(LeadSource $source): array
    {
        return [
            Action::make('funnel_url')
                ->label(__('lead-pipeline::lead-pipeline.funnel.url'))
                ->icon('heroicon-o-link')
                ->modalContent(fn (LeadSource $record) => view('lead-pipeline::filament.pages.webhook-url', [
                    'url' => $this->getWebhookUrl($record),
                ]))
                ->modalSubmitAction(false),
            Action::make('edit_funnel')
                ->label(__('lead-pipeline::lead-pipeline.funnel.edit'))
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (LeadSource $record) => (bool) $record->funnel),
        ];
    }
}
