<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
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
});

function reminderBadgeLead(LeadPhase $phase, array $attributes = []): Lead
{
    return Lead::factory()->create([
        Lead::fkColumn('lead_board') => $phase->{LeadPhase::fkColumn('lead_board')},
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        ...$attributes,
    ]);
}

it('shows a due badge on the card when the reminder is overdue', function (): void {
    reminderBadgeLead($this->phase, ['reminder_at' => now()->subHour()]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->assertSeeHtml('lead-reminder-badge')
        ->assertSee(__('lead-pipeline::lead-pipeline.reminder.due'));
});

it('shows no due badge for upcoming reminders', function (): void {
    reminderBadgeLead($this->phase, ['reminder_at' => now()->addDays(2)]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->assertSeeHtml('lead-reminder-badge')
        ->assertDontSee(__('lead-pipeline::lead-pipeline.reminder.due'));
});

it('counts my reminders due today in the my-day stats', function (): void {
    $mine = (string) $this->user->getKey();

    reminderBadgeLead($this->phase, ['assigned_to' => $mine, 'reminder_at' => now()->subHour()]);
    reminderBadgeLead($this->phase, ['assigned_to' => $mine, 'reminder_at' => now()->addHours(2)]);
    reminderBadgeLead($this->phase, ['assigned_to' => $mine, 'reminder_at' => now()->addDays(3), 'email' => 'spaeter@example.de']);
    reminderBadgeLead($this->phase, ['assigned_to' => null, 'reminder_at' => now()->subHour(), 'email' => 'fremd@example.de']);

    $stats = Livewire::test(KanbanBoard::class, ['board' => $this->board])->instance()->myDayStats;

    expect($stats['due_today'])->toBe(2);
});

it('renders the due-today chip in the strip', function (): void {
    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->assertSee(__('lead-pipeline::lead-pipeline.my_day.due_today'));
});
