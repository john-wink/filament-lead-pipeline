<?php

declare(strict_types=1);

use Illuminate\Notifications\DatabaseNotification;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Notifications\LeadReminderDue;

beforeEach(function (): void {
    $this->user  = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

function reminderCommandLead(LeadPhase $phase, array $attributes = []): Lead
{
    return Lead::factory()->create([
        Lead::fkColumn('lead_board') => $phase->{LeadPhase::fkColumn('lead_board')},
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
        ...$attributes,
    ]);
}

it('notifies the assigned user about due reminders exactly once', function (): void {
    $lead = reminderCommandLead($this->phase, [
        'name'        => 'Maria Weber',
        'assigned_to' => (string) $this->user->getKey(),
        'reminder_at' => now()->subMinutes(10),
    ]);

    $this->artisan('lead-pipeline:send-lead-reminders')->assertSuccessful();

    $notifications = DatabaseNotification::query()->where('type', LeadReminderDue::class)->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->notifiable_id)->toBe((string) $this->user->getKey())
        ->and($lead->refresh()->reminder_notified_at)->not->toBeNull();

    $this->artisan('lead-pipeline:send-lead-reminders')->assertSuccessful();

    expect(DatabaseNotification::query()->where('type', LeadReminderDue::class)->count())->toBe(1);
});

it('skips future, unassigned and inactive leads', function (): void {
    reminderCommandLead($this->phase, [
        'assigned_to' => (string) $this->user->getKey(),
        'reminder_at' => now()->addHour(),
    ]);
    reminderCommandLead($this->phase, [
        'assigned_to' => null,
        'reminder_at' => now()->subHour(),
        'email'       => 'unassigned@example.de',
    ]);
    reminderCommandLead($this->phase, [
        'assigned_to' => (string) $this->user->getKey(),
        'reminder_at' => now()->subHour(),
        'status'      => LeadStatusEnum::Lost,
        'email'       => 'lost@example.de',
    ]);

    $this->artisan('lead-pipeline:send-lead-reminders')->assertSuccessful();

    expect(DatabaseNotification::query()->where('type', LeadReminderDue::class)->count())->toBe(0);
});

it('notifies again after a new reminder was set', function (): void {
    $lead = reminderCommandLead($this->phase, [
        'assigned_to' => (string) $this->user->getKey(),
        'reminder_at' => now()->subMinutes(10),
    ]);

    $this->artisan('lead-pipeline:send-lead-reminders')->assertSuccessful();

    $lead->update(['reminder_at' => now()->subMinute(), 'reminder_notified_at' => null]);

    $this->artisan('lead-pipeline:send-lead-reminders')->assertSuccessful();

    expect(DatabaseNotification::query()->where('type', LeadReminderDue::class)->count())->toBe(2);
});
