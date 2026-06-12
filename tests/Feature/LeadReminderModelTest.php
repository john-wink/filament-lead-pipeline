<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

beforeEach(function (): void {
    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

it('adds the reminder columns to the leads table', function (): void {
    expect(Schema::hasColumn('leads', 'reminder_at'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'reminder_note'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'reminder_notified_at'))->toBeTrue();
});

it('has a follow up activity type', function (): void {
    expect(LeadActivityTypeEnum::FollowUp->value)->toBe('follow_up')
        ->and(LeadActivityTypeEnum::FollowUp->getLabel())->not->toBeEmpty()
        ->and(LeadActivityTypeEnum::FollowUp->getIcon())->toStartWith('heroicon-')
        ->and(LeadActivityTypeEnum::FollowUp->getColor())->not->toBeEmpty();
});

it('knows whether a reminder is due', function (): void {
    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);

    expect($lead->hasDueReminder())->toBeFalse();

    $lead->update(['reminder_at' => now()->addHour()]);
    expect($lead->refresh()->hasDueReminder())->toBeFalse();

    $lead->update(['reminder_at' => now()->subMinute()]);
    expect($lead->refresh()->hasDueReminder())->toBeTrue();
});

it('casts reminder_at to a datetime', function (): void {
    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        'reminder_at'                => now()->addDay(),
    ]);

    expect($lead->refresh()->reminder_at)->toBeInstanceOf(Carbon\CarbonInterface::class);
});
