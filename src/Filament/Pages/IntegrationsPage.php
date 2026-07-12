<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Contracts\LeadIntegrationContract;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use Throwable;

class IntegrationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string $view = 'lead-pipeline::filament.pages.integrations';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('lead-pipeline::lead-pipeline.integrations.title');
    }

    /** @return array<string, LeadIntegrationContract> */
    public function getIntegrations(): array
    {
        try {
            return FilamentLeadPipelinePlugin::get()->getIntegrations();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Fail-closed per integration: a throwing isActivatedFor() must not
     * crash the whole page — it just renders that card as inactive.
     */
    public function isIntegrationActivated(LeadIntegrationContract $integration): bool
    {
        $tenant = filament()->getTenant();

        if ( ! $tenant instanceof Model) {
            return false;
        }

        try {
            return $integration->isActivatedFor($tenant);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Fail-closed per integration: a throwing settingsComponent() must not
     * crash the whole page — the settings island for that card is skipped.
     *
     * @return class-string|null
     */
    public function resolveSettingsComponent(LeadIntegrationContract $integration): ?string
    {
        try {
            return $integration->settingsComponent();
        } catch (Throwable) {
            return null;
        }
    }
}
