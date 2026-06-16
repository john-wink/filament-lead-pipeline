<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Services\LeadTransferService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    $this->origin = LeadBoard::factory()->withDefaultPhases()->create([
        'team_uuid' => $this->team->getKey(),
        'settings'  => ['transfer_enabled' => true],
    ]);
    $this->target = LeadBoard::factory()->withDefaultPhases()->create(['team_uuid' => $this->team->getKey()]);

    $this->lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->origin->getKey(),
        Lead::fkColumn('lead_phase') => $this->origin->phases()->where('type', LeadPhaseTypeEnum::Won)->first()->getKey(),
    ]);
});

it('transfers a lead to a target board via the modal', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->set('transferTargetBoardId', $this->target->getKey())
        ->set('transferNote', 'Bitte zügig anrufen.')
        ->call('transferToBoard')
        ->assertHasNoErrors();

    expect(Lead::query()->where('external_id', $this->lead->getKey())
        ->where(Lead::fkColumn('lead_board'), $this->target->getKey())->exists())->toBeTrue();
});

it('requires a note before transferring', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->set('transferTargetBoardId', $this->target->getKey())
        ->set('transferNote', '')
        ->call('transferToBoard')
        ->assertHasErrors(['transferNote']);
});

it('lists only accessible target boards excluding the current one', function (): void {
    $component = Livewire::test(LeadDetailModal::class)->call('openModal', $this->lead->getKey());
    $ids       = collect($component->instance()->transferableBoards())->pluck(LeadBoard::pkColumn())->all();

    expect($ids)->toContain($this->target->getKey())
        ->and($ids)->not->toContain($this->origin->getKey());
});

it('shows origin history read-through on the transferred lead', function (): void {
    $new = app(LeadTransferService::class)->transfer($this->lead, $this->target, null, null, 'note');

    $component = Livewire::test(LeadDetailModal::class)->call('openModal', $new->getKey());

    expect($component->instance()->originLead()?->getKey())->toBe($this->lead->getKey());
});

it('opens the transfer form after winning when prompt_on_won is set', function (): void {
    $this->origin->update(['settings' => ['transfer_enabled' => true, 'prompt_on_won' => true]]);

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('markAsWon')
        ->assertSet('showTransferForm', true);
});

it('does not open the transfer form when prompt_on_won is off', function (): void {
    $this->origin->update(['settings' => ['transfer_enabled' => true, 'prompt_on_won' => false]]);

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('markAsWon')
        ->assertSet('showTransferForm', false);
});
