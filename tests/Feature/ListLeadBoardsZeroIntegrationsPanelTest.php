<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\ListLeadBoards;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Concerns\RegistersZeroIntegrationsPanel;

use function Pest\Livewire\livewire;

/**
 * Regression guard for ListLeadBoards' "integrations" header action URL.
 *
 * The AdminPanelProvider fixture always boots WITH an integration, so its
 * IntegrationsPage route always exists — a test on that panel can't tell a
 * lazy `->url(fn () => IntegrationsPage::getUrl())` apart from an eager
 * `->url(IntegrationsPage::getUrl())`, because the eager call would still
 * resolve successfully. This test boots a second panel with ZERO
 * integrations (via the RegistersZeroIntegrationsPanel trait, scoped to
 * this file only), where IntegrationsPage was never registered as a page
 * and therefore has no route. An eager url() call would throw a
 * RouteNotFoundException the moment ListLeadBoards builds its header
 * actions — regardless of the action's visibility.
 */
uses(RegistersZeroIntegrationsPanel::class);

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('zero-integrations'));
    filament()->setTenant($this->team);
});

it('renders ListLeadBoards on a panel with zero integrations, with the integrations action absent', function (): void {
    livewire(ListLeadBoards::class)
        ->assertOk()
        ->assertActionHidden('integrations');
});
