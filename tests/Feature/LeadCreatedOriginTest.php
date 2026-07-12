<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\LeadOriginEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

function originTestLead(): Lead
{
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    return Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);
}

it('covers the three lead origins', function (): void {
    expect(array_map(fn (LeadOriginEnum $origin): string => $origin->value, LeadOriginEnum::cases()))
        ->toBe(['realtime', 'import', 'manual']);
});

it('defaults the event origin to realtime', function (): void {
    $event = new LeadCreated(originTestLead());

    expect($event->origin)->toBe(LeadOriginEnum::Realtime);
});

it('carries an explicit origin', function (): void {
    $lead  = originTestLead();
    $event = new LeadCreated($lead, LeadOriginEnum::Import);

    expect($event->origin)->toBe(LeadOriginEnum::Import)
        ->and($event->lead->is($lead))->toBeTrue();
});

it('keeps the single argument dispatch call working', function (): void {
    Event::fake([LeadCreated::class]);

    LeadCreated::dispatch(originTestLead());

    Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => LeadOriginEnum::Realtime === $event->origin);
});
