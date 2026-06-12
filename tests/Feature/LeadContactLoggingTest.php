<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->actingAs($this->user);

    $this->board = LeadBoard::factory()->create();
    $this->board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
    $this->lead  = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        'name'                       => 'Maria Weber',
        'email'                      => 'maria@example.de',
        'phone'                      => '+49 170 1234567',
    ]);
});

// === MODAL ===

it('logs a call activity when the phone contact is used in the modal', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('logContact', 'phone');

    $activity = $this->lead->activities()->where('type', LeadActivityTypeEnum::Call->value)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->properties['channel'])->toBe('phone')
        ->and($activity->properties['target'])->toBe('+49 170 1234567');
});

it('logs an email activity when the email contact is used in the modal', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('logContact', 'email');

    $activity = $this->lead->activities()->where('type', LeadActivityTypeEnum::Email->value)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->properties['target'])->toBe('maria@example.de');
});

it('ignores unknown contact channels', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('logContact', 'fax');

    expect($this->lead->activities()->whereIn('type', [
        LeadActivityTypeEnum::Call->value,
        LeadActivityTypeEnum::Email->value,
    ])->count())->toBe(0);
});

it('wires the modal contact links to the logging action', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSeeHtml('tel:+49 170 1234567')
        ->assertSeeHtml('mailto:maria@example.de')
        ->assertSeeHtml("logContact('phone')")
        ->assertSeeHtml("logContact('email')");
});

// === KARTE (Phase-Column-Kontext) ===

it('logs a call activity from the card', function (): void {
    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->call('logContact', $this->lead->getKey(), 'phone');

    $activity = $this->lead->activities()->where('type', LeadActivityTypeEnum::Call->value)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->user->getKey());
});

it('does not log contacts for leads of another board', function (): void {
    $foreignBoard = LeadBoard::factory()->create();
    $foreignPhase = LeadPhase::factory()->for($foreignBoard, 'board')->open()->create(['sort' => 0]);
    $foreignLead  = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $foreignBoard->getKey(),
        Lead::fkColumn('lead_phase') => $foreignPhase->getKey(),
        'phone'                      => '+49 1',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->call('logContact', $foreignLead->getKey(), 'phone');

    expect($foreignLead->activities()->where('type', LeadActivityTypeEnum::Call->value)->count())->toBe(0);
});

it('wires the card contact links to the logging action', function (): void {
    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->assertSeeHtml('tel:+49 170 1234567')
        ->assertSeeHtml('mailto:maria@example.de')
        ->assertSeeHtml('logContact');
});
