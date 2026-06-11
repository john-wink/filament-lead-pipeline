<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use JohnWink\FilamentLeadPipeline\Commands\GenerateDemoDataCommand;
use JohnWink\FilamentLeadPipeline\Commands\RefreshFacebookTokensCommand;
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
            ->hasRoutes(['api'])
            ->hasCommands([
                GenerateDemoDataCommand::class,
                Commands\ConnectFacebookCommand::class,
                Commands\FacebookWebhookStatusCommand::class,
                RefreshFacebookTokensCommand::class,
                Commands\SyncMetaReportsCommand::class,
                Commands\SendScheduledReportsCommand::class,
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

        $this->app->bind(
            Contracts\ResolvesReportBranding::class,
            fn () => $this->app->make(config('lead-pipeline.reports.branding_resolver', Services\ConfigReportBrandingResolver::class)),
        );

        $this->app->bind(
            Contracts\ReportPdfRenderer::class,
            fn () => $this->app->make(config('lead-pipeline.reports.pdf_renderer', Services\NullReportPdfRenderer::class)),
        );
    }

    public function packageBooted(): void
    {
        \Illuminate\Support\Facades\Gate::policy(Models\LeadReport::class, Policies\LeadReportPolicy::class);

        FilamentAsset::register([
            Css::make('lead-pipeline-styles', __DIR__ . '/../resources/dist/lead-pipeline.css'),
            Css::make('lead-pipeline-reports', __DIR__ . '/../resources/dist/lead-pipeline-reports.css')->loadedOnRequest(),
            Js::make('lead-pipeline-sortable', __DIR__ . '/../resources/dist/sortable.min.js'),
            Js::make('lead-pipeline-scripts', __DIR__ . '/../resources/dist/lead-pipeline.js'),
        ], 'john-wink/filament-lead-pipeline');
    }

    public function boot(): void
    {
        parent::boot();

        if (config('lead-pipeline.facebook.refresh.enabled', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $event = $schedule->command(RefreshFacebookTokensCommand::class)
                    ->withoutOverlapping()
                    ->onOneServer();

                $cadence = (string) config('lead-pipeline.facebook.refresh.cadence', 'hourly');
                $allowed = ['everyMinute', 'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes', 'hourly', 'daily', 'twiceDaily', 'weekly'];
                in_array($cadence, $allowed, true) ? $event->{$cadence}() : $event->hourly();
            });
        }

        if (config('lead-pipeline.reports.sync.enabled', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(Commands\SyncMetaReportsCommand::class)
                    ->dailyAt((string) config('lead-pipeline.reports.sync.daily_at', '04:00'))
                    ->withoutOverlapping()->onOneServer();

                if (config('lead-pipeline.reports.sync.hourly_current_day', true)) {
                    $schedule->command(Commands\SyncMetaReportsCommand::class, ['--days' => 1, '--skip-creatives' => true])
                        ->hourly()->withoutOverlapping()->onOneServer();
                }
            });
        }

        if (config('lead-pipeline.reports.scheduling.enabled', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(Commands\SendScheduledReportsCommand::class)
                    ->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
            });
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerLivewireComponents();

        Models\Lead::observe(Observers\LeadObserver::class);

        // Register funnel web routes LAST so they don't catch other routes
        // when route_prefix is empty (/{slug} would match everything)
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::component('lead-pipeline::public-report-page', \JohnWink\FilamentLeadPipeline\Livewire\PublicReportPage::class);
        Livewire::component('lead-pipeline::kanban-board', \JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard::class);
        Livewire::component('lead-pipeline::kanban-phase-column', \JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn::class);
        Livewire::component('lead-pipeline::lead-card', \JohnWink\FilamentLeadPipeline\Livewire\LeadCard::class);
        Livewire::component('lead-pipeline::lead-detail-modal', \JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal::class);
        Livewire::component('lead-pipeline::funnel-wizard', \JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard::class);
        Livewire::component('lead-pipeline::funnel-builder', \JohnWink\FilamentLeadPipeline\Livewire\FunnelBuilder::class);
        Livewire::component('lead-pipeline::phase-list-table', \JohnWink\FilamentLeadPipeline\Livewire\PhaseListTable::class);
        Livewire::component('lead-pipeline::lead-analytics-modal', \JohnWink\FilamentLeadPipeline\Livewire\LeadAnalyticsModal::class);
    }
}
