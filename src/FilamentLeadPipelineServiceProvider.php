<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use JohnWink\FilamentLeadPipeline\Commands\GenerateDemoDataCommand;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentLeadPipelineServiceProvider extends PackageServiceProvider
{
    public static string $name = 'lead-pipeline';

    public static string $viewNamespace = 'lead-pipeline';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$viewNamespace)
            ->hasTranslations()
            ->hasRoutes(['web', 'api'])
            ->hasMigrations($this->getMigrations())
            ->hasCommands([
                GenerateDemoDataCommand::class,
                Commands\ConnectFacebookCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('john-wink/filament-lead-pipeline');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Services\LeadSourceManager::class);
        $this->app->singleton(Services\LeadConversionService::class);
        $this->app->singleton(Services\FacebookGraphService::class);
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Css::make('lead-pipeline-styles', __DIR__ . '/../resources/dist/lead-pipeline.css'),
            Js::make('lead-pipeline-sortable', __DIR__ . '/../resources/dist/sortable.min.js'),
            Js::make('lead-pipeline-scripts', __DIR__ . '/../resources/dist/lead-pipeline.js'),
        ], 'john-wink/filament-lead-pipeline');
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerLivewireComponents();
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::component('lead-pipeline::kanban-board', \JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard::class);
        Livewire::component('lead-pipeline::kanban-phase-column', \JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn::class);
        Livewire::component('lead-pipeline::lead-card', \JohnWink\FilamentLeadPipeline\Livewire\LeadCard::class);
        Livewire::component('lead-pipeline::lead-detail-modal', \JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal::class);
        Livewire::component('lead-pipeline::funnel-wizard', \JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard::class);
        Livewire::component('lead-pipeline::funnel-builder', \JohnWink\FilamentLeadPipeline\Livewire\FunnelBuilder::class);
        Livewire::component('lead-pipeline::phase-list-table', \JohnWink\FilamentLeadPipeline\Livewire\PhaseListTable::class);
        Livewire::component('lead-pipeline::lead-analytics-modal', \JohnWink\FilamentLeadPipeline\Livewire\LeadAnalyticsModal::class);
    }

    /** @return array<string> */
    protected function getMigrations(): array
    {
        return [
            '0001_create_lead_boards_table',
            '0002_create_lead_phases_table',
            '0003_create_lead_field_definitions_table',
            '0004_create_leads_table',
            '0005_create_lead_field_values_table',
            '0006_create_lead_sources_table',
            '0007_create_lead_funnels_table',
            '0008_create_lead_funnel_steps_table',
            '0009_create_lead_funnel_step_fields_table',
            '0010_create_lead_activities_table',
            '0011_create_lead_conversions_table',
            '0012_create_lead_board_admins_table',
            '0013_create_facebook_connections_table',
            '0014_create_facebook_pages_table',
            '0015_create_facebook_forms_table',
            '0016_add_facebook_fields_to_lead_sources_table',
            '0017_add_created_by_to_lead_sources_table',
        ];
    }
}
