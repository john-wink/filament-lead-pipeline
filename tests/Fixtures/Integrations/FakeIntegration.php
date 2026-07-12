<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Integrations;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Contracts\LeadIntegrationContract;
use JohnWink\FilamentLeadPipeline\DTOs\IntegrationActionData;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use RuntimeException;

class FakeIntegration implements LeadIntegrationContract
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake Integration';
    }

    public function icon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public function isActivatedFor(Model $tenant): bool
    {
        return (bool) config('lead-pipeline.testing.fake_integration_active', false);
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

    /** @return array<int, IntegrationActionData> */
    public function leadModalActions(Lead $lead): array
    {
        $requiresConfirmation = (bool) config('lead-pipeline.testing.fake_integration_confirm', false);

        return [
            new IntegrationActionData(
                key: 'ping',
                label: 'Fake anrufen',
                icon: 'heroicon-o-phone-arrow-up-right',
                color: 'primary',
                requiresConfirmation: $requiresConfirmation,
                confirmText: $requiresConfirmation ? 'Wirklich anrufen?' : null,
            ),
        ];
    }

    public function handleLeadAction(string $actionKey, Lead $lead): void
    {
        if (config('lead-pipeline.testing.fake_integration_throws', false)) {
            throw new RuntimeException('Fake-Integration fehlgeschlagen');
        }

        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Integration->value,
            'description' => sprintf('Fake-Aktion "%s" ausgeführt', $actionKey),
            'properties'  => ['integration' => $this->key(), 'action' => $actionKey, 'result' => 'ok'],
        ]);
    }

    public function renderActivity(LeadActivity $activity): ?View
    {
        if (config('lead-pipeline.testing.fake_integration_render_throws', false)) {
            throw new RuntimeException('Fake render failure');
        }

        if ( ! config('lead-pipeline.testing.fake_integration_renders', true)) {
            return null;
        }

        return view()->file(
            __DIR__ . '/../views/fake-integration-activity.blade.php',
            ['activity' => $activity],
        );
    }
}
