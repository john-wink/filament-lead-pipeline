<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\CreateLeadBoard;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    LeadBoard::created(function (LeadBoard $board): void {
        $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    });
});

it('shows an activated integration\'s board form components on the edit form', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_board_components', true);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertOk()
        ->assertSee('Fake Setting');
});

it('keeps the edit form rendering when boardFormComponents throws', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_board_throws', true);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertOk()
        ->assertDontSee('Fake Setting');
});

it('contributes nothing for integrations not activated for the tenant', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_board_components', true);
    // fake_integration_active left at its default (false) - not activated for the tenant.

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertOk()
        ->assertDontSee('Fake Setting');

    expect(LeadBoardResource::getIntegrationBoardFormComponents($board))->toBe([]);
});

it('leaves the create form unchanged since integrations need an existing board', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_board_components', true);

    livewire(CreateLeadBoard::class)
        ->assertOk()
        ->assertDontSee('Fake Setting');

    expect(LeadBoardResource::getIntegrationBoardFormComponents(null))->toBe([]);
});

it('renders an unchanged form when zero integrations are registered', function (): void {
    /** @var FilamentLeadPipelinePlugin $plugin */
    $plugin = filament()->getPlugin('filament-lead-pipeline');
    $plugin->integrations([]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    expect(LeadBoardResource::getIntegrationBoardFormComponents($board))->toBe([]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertOk()
        ->assertDontSee('Fake Setting');
});
