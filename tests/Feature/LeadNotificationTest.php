<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\LeadPipeline\LeadAssignedNotification;
use App\Notifications\LeadPipeline\LeadCreatedNotification;
use App\Notifications\LeadPipeline\LeadMovedNotification;
use App\Notifications\LeadPipeline\LeadStatusChangedNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadAssigned;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Events\LeadMoved;
use JohnWink\FilamentLeadPipeline\Events\LeadStatusChanged;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
    $this->board = LeadBoard::factory()->create();
    $this->board->admins()->attach($this->admin->getKey());
    $this->phase  = LeadPhase::factory()->for($this->board, 'board')->create();
    $this->source = LeadSource::factory()->for($this->board, 'board')->create();
});

it('sends notification to board admins when lead is created', function (): void {
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->for($this->source, 'source')->create();

    event(new LeadCreated($lead));

    Notification::assertSentTo($this->admin, LeadCreatedNotification::class);
});

it('does not send created notification when no admins exist', function (): void {
    $this->board->admins()->detach();
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    event(new LeadCreated($lead));

    Notification::assertNothingSent();
});

it('sends notification to assigned user', function (): void {
    $advisor = User::factory()->create();
    $lead    = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    event(new LeadAssigned($lead, $advisor, $this->admin));

    Notification::assertSentTo($advisor, LeadAssignedNotification::class);
});

it('does not send assigned notification when no user', function (): void {
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    event(new LeadAssigned($lead, null, $this->admin));

    Notification::assertNothingSent();
});

it('sends moved notification to assigned user', function (): void {
    $advisor  = User::factory()->create();
    $newPhase = LeadPhase::factory()->for($this->board, 'board')->create(['name' => 'Neue Phase']);
    $lead     = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['assigned_to' => $advisor->getKey()]);

    event(new LeadMoved($lead, $this->phase, $newPhase));

    Notification::assertSentTo($advisor, LeadMovedNotification::class);
});

it('does not send moved notification when no user assigned', function (): void {
    $newPhase = LeadPhase::factory()->for($this->board, 'board')->create();
    $lead     = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['assigned_to' => null]);

    event(new LeadMoved($lead, $this->phase, $newPhase));

    Notification::assertNothingSent();
});

it('sends status changed notification to board admins', function (): void {
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    event(new LeadStatusChanged($lead, LeadStatusEnum::Active, LeadStatusEnum::Won));

    Notification::assertSentTo($this->admin, LeadStatusChangedNotification::class);
});

// ==========================================
// EVENT DISPATCH INTEGRATION TESTS
// ==========================================

it('dispatches LeadMoved event when moveToPhase is called', function (): void {
    $newPhase = LeadPhase::factory()->for($this->board, 'board')->create(['name' => 'In Bearbeitung']);
    $lead     = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    // Verify moveToPhase updates the phase
    $lead->moveToPhase($newPhase);

    expect($lead->refresh()->{Lead::fkColumn('lead_phase')})->toBe($newPhase->getKey());
    expect($lead->activities()->where('type', \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Moved->value)->exists())->toBeTrue();
});

it('moves lead to new phase and creates activity on auto-move', function (): void {
    $openPhase     = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
    $progressPhase = LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => \JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum::InProgress,
        'sort' => 1,
    ]);
    $lead = Lead::factory()->for($openPhase, 'phase')->for($this->board, 'board')->create();

    $lead->moveToPhase($progressPhase);

    expect($lead->refresh()->{Lead::fkColumn('lead_phase')})->toBe($progressPhase->getKey());
});

it('does not send duplicate LeadAssigned notifications on single assignment', function (): void {
    $advisor = User::factory()->create();
    $lead    = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    LeadAssigned::dispatch($lead, $advisor, $this->admin);

    Notification::assertSentToTimes($advisor, LeadAssignedNotification::class, 1);
});

it('moves lead to won phase via moveToPhase', function (): void {
    $wonPhase = LeadPhase::factory()->for($this->board, 'board')->won()->create(['sort' => 5]);
    $lead     = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    $lead->moveToPhase($wonPhase);

    expect($lead->refresh()->{Lead::fkColumn('lead_phase')})->toBe($wonPhase->getKey());
});
