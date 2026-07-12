<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\DTOs\IntegrationActionData;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

/**
 * A third-party integration that plugs into the lead pipeline.
 *
 * Integrations are registered on the plugin via integrations([...]) and
 * contribute a settings component on the integrations page, optional
 * board form components, actions in the lead detail modal and custom
 * rendering for Integration-type activities in the timeline.
 */
interface LeadIntegrationContract
{
    /** Unique registry key, e.g. 'my-dialer'. */
    public function key(): string;

    /** Human readable name shown on cards and buttons. */
    public function label(): string;

    /** Heroicon name, e.g. 'heroicon-o-phone'. */
    public function icon(): string;

    /** Whether the integration is configured and active for the given tenant. */
    public function isActivatedFor(Model $tenant): bool;

    /** @return class-string Livewire component rendered on the integrations page. */
    public function settingsComponent(): string;

    /**
     * Additional form schema components for the LeadBoard edit form.
     *
     * @return array<int, mixed>
     */
    public function boardFormComponents(LeadBoard $board): array;

    /**
     * Actions offered in the lead detail modal for this lead.
     *
     * @return array<int, IntegrationActionData>
     */
    public function leadModalActions(Lead $lead): array;

    /** Executes a modal action; throw to surface an error notification. */
    public function handleLeadAction(string $actionKey, Lead $lead): void;

    /**
     * Custom rendering for an Integration-type activity;
     * null falls back to the generic timeline entry.
     */
    public function renderActivity(LeadActivity $activity): ?View;
}
