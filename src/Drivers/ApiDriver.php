<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Drivers;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Actions\Action;
use JohnWink\FilamentLeadPipeline\Contracts\LeadSourceDriver;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class ApiDriver implements LeadSourceDriver
{
    public function getDisplayName(): string
    {
        return 'API';
    }

    public function validateConfig(array $config): bool
    {
        return true;
    }

    public function processWebhook(WebhookPayloadData $payload, LeadSource $source): LeadData
    {
        $config       = $source->config ?? [];
        $fieldMapping = $config['field_mapping'] ?? $this->getDefaultFieldMapping();

        $rawPayload = $payload->raw_payload;

        return new LeadData(
            name: (string) ($rawPayload[$fieldMapping['name'] ?? 'name'] ?? ''),
            email: isset($rawPayload[$fieldMapping['email'] ?? 'email'])
                ? (string) $rawPayload[$fieldMapping['email'] ?? 'email']
                : null,
            phone: isset($rawPayload[$fieldMapping['phone'] ?? 'phone'])
                ? (string) $rawPayload[$fieldMapping['phone'] ?? 'phone']
                : null,
            custom_fields: array_diff_key($rawPayload, array_flip(array_values($fieldMapping))),
            source_driver: 'api',
            source_identifier: (string) $source->getKey(),
            value: isset($rawPayload['value']) ? (float) $rawPayload['value'] : null,
        );
    }

    public function verifySignature(string $payload, string $signature, LeadSource $source): bool
    {
        $token = $source->api_token ?? '';

        $bearerToken = str_starts_with($signature, 'Bearer ')
            ? mb_substr($signature, 7)
            : $signature;

        return hash_equals($token, $bearerToken);
    }

    /** @return array<\Filament\Forms\Components\Component> */
    public function getConfigFormSchema(): array
    {
        return [
            TextInput::make('config.field_mapping.name')
                ->label(__('lead-pipeline::lead-pipeline.api_driver.name_field'))
                ->default('name'),
            TextInput::make('config.field_mapping.email')
                ->label(__('lead-pipeline::lead-pipeline.api_driver.email_field'))
                ->default('email'),
            TextInput::make('config.field_mapping.phone')
                ->label(__('lead-pipeline::lead-pipeline.api_driver.phone_field'))
                ->default('phone'),
        ];
    }

    public function getWebhookUrl(LeadSource $source): string
    {
        $prefix = config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks');

        return \JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::publicUrl("{$prefix}/{$source->getKey()}");
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
            Action::make('webhook_url')
                ->label(__('lead-pipeline::lead-pipeline.source.webhook_url'))
                ->icon('heroicon-o-link')
                ->modalContent(fn (LeadSource $record) => view('lead-pipeline::filament.pages.webhook-url', [
                    'url' => $this->getWebhookUrl($record),
                ]))
                ->modalSubmitAction(false),
        ];
    }
}
