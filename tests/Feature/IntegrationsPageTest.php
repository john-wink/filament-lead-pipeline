<?php

declare(strict_types=1);

use App\Models\Team;
use Filament\Panel;
use JohnWink\FilamentLeadPipeline\Filament\Pages\IntegrationsPage;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Integrations\FakeIntegration;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('renders a card per registered integration with the settings component island', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);

    livewire(IntegrationsPage::class)
        ->assertOk()
        ->assertSee(__('lead-pipeline::lead-pipeline.integrations.title'))
        ->assertSee('Fake Integration')
        ->assertSee(__('lead-pipeline::lead-pipeline.integrations.active'))
        ->assertSee('Fake-Integration Einstellungen');
});

it('shows the inactive badge when the integration is not activated for the tenant', function (): void {
    livewire(IntegrationsPage::class)
        ->assertOk()
        ->assertSee(__('lead-pipeline::lead-pipeline.integrations.inactive'));
});

it('keeps the page rendering as inactive when isActivatedFor throws for an integration', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_activation_throws', true);

    livewire(IntegrationsPage::class)
        ->assertOk()
        ->assertSee('Fake Integration')
        ->assertSee(__('lead-pipeline::lead-pipeline.integrations.inactive'));
});

it('keeps the page rendering and skips the settings island when settingsComponent throws', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_settings_component_throws', true);

    livewire(IntegrationsPage::class)
        ->assertOk()
        ->assertSee('Fake Integration')
        ->assertDontSee('Fake-Integration Einstellungen');
});

it('registers the integrations page only when integrations are configured', function (): void {
    $panelWithout = Panel::make()->id('integration-test-without');
    FilamentLeadPipelinePlugin::make()->register($panelWithout);

    expect($panelWithout->getPages())->not->toContain(IntegrationsPage::class);

    $panelWith = Panel::make()->id('integration-test-with');
    FilamentLeadPipelinePlugin::make()->integrations([FakeIntegration::class])->register($panelWith);

    expect($panelWith->getPages())->toContain(IntegrationsPage::class);
});
