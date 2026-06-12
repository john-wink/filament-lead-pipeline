<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->actingAs($this->user);

    $board      = LeadBoard::factory()->create();
    $phase      = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $this->lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Maria Weber',
    ]);
});

it('sets a reminder with note and follow up activity', function (): void {
    $this->lead->update(['reminder_notified_at' => now()->subDay()]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->set('reminderAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->set('reminderNote', 'Rückruf wegen Budget')
        ->call('setReminder')
        ->assertHasNoErrors();

    $this->lead->refresh();

    expect($this->lead->reminder_at)->not->toBeNull()
        ->and($this->lead->reminder_note)->toBe('Rückruf wegen Budget')
        ->and($this->lead->reminder_notified_at)->toBeNull()
        ->and($this->lead->activities()->where('type', LeadActivityTypeEnum::FollowUp->value)->count())->toBe(1);
});

it('rejects reminders in the past', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->set('reminderAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('setReminder')
        ->assertHasErrors(['reminderAt']);

    expect($this->lead->refresh()->reminder_at)->toBeNull();
});

it('clears an existing reminder', function (): void {
    $this->lead->update(['reminder_at' => now()->addDay(), 'reminder_note' => 'Alt']);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('clearReminder');

    $this->lead->refresh();

    expect($this->lead->reminder_at)->toBeNull()
        ->and($this->lead->reminder_note)->toBeNull()
        ->and($this->lead->activities()->where('type', LeadActivityTypeEnum::FollowUp->value)->count())->toBe(1);
});

it('shows the active reminder in the modal', function (): void {
    $this->lead->update(['reminder_at' => now()->addDay()->setTime(10, 30), 'reminder_note' => 'Budget klären']);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee(__('lead-pipeline::lead-pipeline.reminder.title'))
        ->assertSee(now()->addDay()->setTime(10, 30)->format('d.m.Y H:i'))
        ->assertSee('Budget klären');
});

it('offers the reminder form when none is set', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee(__('lead-pipeline::lead-pipeline.reminder.title'))
        ->assertSeeHtml('wire:model="reminderAt"')
        ->assertSeeHtml('setReminder');
});
