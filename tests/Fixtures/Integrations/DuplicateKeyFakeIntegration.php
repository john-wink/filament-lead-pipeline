<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Integrations;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Contracts\LeadIntegrationContract;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

/**
 * Deliberately resolves to the same key() as FakeIntegration, used only to
 * exercise the registry's duplicate-key guard in getIntegrations().
 */
class DuplicateKeyFakeIntegration implements LeadIntegrationContract
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Duplicate Fake Integration';
    }

    public function icon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public function isActivatedFor(Model $tenant): bool
    {
        return false;
    }

    public function settingsComponent(): string
    {
        return FakeIntegrationSettings::class;
    }

    /** @return array<int, mixed> */
    public function boardFormComponents(LeadBoard $board): array
    {
        return [];
    }

    /** @return array<int, \JohnWink\FilamentLeadPipeline\DTOs\IntegrationActionData> */
    public function leadModalActions(Lead $lead): array
    {
        return [];
    }

    public function handleLeadAction(string $actionKey, Lead $lead): void
    {
        // Not exercised by the registry-collision test.
    }

    public function renderActivity(LeadActivity $activity): ?View
    {
        return null;
    }
}
