<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;

/**
 * A second panel fixture booted with ZERO integrations, so the
 * IntegrationsPage route never gets registered for it. Used to catch a
 * regression from a lazy `->url(fn () => ...)` to an eager
 * `->url(...)` on the ListLeadBoards "integrations" header action: the
 * AdminPanelProvider fixture always boots WITH an integration, so its
 * IntegrationsPage route always exists and can't discriminate between
 * the two.
 */
class ZeroIntegrationsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('zero-integrations')
            ->path('zero-integrations')
            ->tenant(Team::class)
            ->plugin(
                FilamentLeadPipelinePlugin::make()
                    ->integrations([]),
            )
            ->middleware([
                DispatchServingFilamentEvent::class,
                DisableBladeIconComponents::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
