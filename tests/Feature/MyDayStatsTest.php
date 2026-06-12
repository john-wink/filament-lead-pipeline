<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->actingAs($this->user);

    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

function myDayLead(LeadPhase $phase, array $attributes = []): Lead
{
    return Lead::factory()->create([
        Lead::fkColumn('lead_board') => $phase->{LeadPhase::fkColumn('lead_board')},
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
        ...$attributes,
    ]);
}

it('aggregates my personal day stats scoped to me and the board', function (): void {
    $mine = (string) $this->user->getKey();

    myDayLead($this->phase, ['assigned_to' => $mine]);
    myDayLead($this->phase, ['assigned_to' => $mine]);
    $older = myDayLead($this->phase, ['assigned_to' => $mine, 'created_at' => now()->subDays(3)]);
    myDayLead($this->phase, ['assigned_to' => $mine, 'status' => LeadStatusEnum::Won]);
    myDayLead($this->phase, ['assigned_to' => null]);

    $older->activities()->create([
        'type'        => LeadActivityTypeEnum::Call->value,
        'description' => 'Anruf',
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => $mine,
    ]);

    $stats = Livewire::test(KanbanBoard::class, ['board' => $this->board])->instance()->myDayStats;

    expect($stats['new_today'])->toBe(3)
        ->and($stats['contacted_today'])->toBe(1)
        ->and($stats['won_week'])->toBe(1)
        ->and($stats['open_mine'])->toBe(3);
});

it('counts contacted leads only once regardless of attempts', function (): void {
    $lead = myDayLead($this->phase, ['assigned_to' => (string) $this->user->getKey()]);

    foreach (['phone', 'phone', 'email'] as $channel) {
        $lead->logContactAttempt($channel);
    }

    $stats = Livewire::test(KanbanBoard::class, ['board' => $this->board])->instance()->myDayStats;

    expect($stats['contacted_today'])->toBe(1);
});

it('ignores contacts from other users and other days', function (): void {
    $lead = myDayLead($this->phase, ['assigned_to' => (string) $this->user->getKey()]);

    $lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Call->value,
        'description' => 'Fremder Anruf',
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => 'someone-else',
    ]);
    $lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Call->value,
        'description' => 'Gestern',
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => (string) $this->user->getKey(),
    ])->forceFill(['created_at' => now()->subDay()])->save();

    $stats = Livewire::test(KanbanBoard::class, ['board' => $this->board])->instance()->myDayStats;

    expect($stats['contacted_today'])->toBe(0);
});

it('renders the my-day strip on the board page', function (): void {
    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->assertSee(__('lead-pipeline::lead-pipeline.my_day.title'))
        ->assertSee(__('lead-pipeline::lead-pipeline.my_day.new_today'))
        ->assertSee(__('lead-pipeline::lead-pipeline.my_day.contacted_today'))
        ->assertSee(__('lead-pipeline::lead-pipeline.my_day.won_week'))
        ->assertSee(__('lead-pipeline::lead-pipeline.my_day.open_mine'));
});
