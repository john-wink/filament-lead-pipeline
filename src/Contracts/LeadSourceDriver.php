<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

interface LeadSourceDriver
{
    public function getDisplayName(): string;

    public function validateConfig(array $config): bool;

    public function processWebhook(WebhookPayloadData $payload, LeadSource $source): LeadData;

    public function verifySignature(string $payload, string $signature, LeadSource $source): bool;

    /** @return array<\Filament\Forms\Components\Component> */
    public function getConfigFormSchema(): array;

    public function getWebhookUrl(LeadSource $source): string;

    /** @return array<string, string> */
    public function getDefaultFieldMapping(): array;

    /** @return array<\Filament\Tables\Actions\Action> */
    public function getTableActions(LeadSource $source): array;
}
