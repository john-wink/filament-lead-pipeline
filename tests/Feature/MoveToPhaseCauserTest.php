<?php

declare(strict_types=1);

use App\Models\User;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

it('records no causer when moved without an authenticated user', function (): void {
    $lead   = Lead::factory()->create();
    $target = LeadPhase::factory()->create([LeadPhase::fkColumn('lead_board') => $lead->{Lead::fkColumn('lead_board')}]);

    $lead->moveToPhase($target);

    $activity = $lead->activities()->where('type', LeadActivityTypeEnum::Moved->value)->latest()->first();
    expect($activity->causer_id)->toBeNull();
});

it('attributes the move to the authenticated user', function (): void {
    $user   = User::factory()->create();
    $lead   = Lead::factory()->create();
    $target = LeadPhase::factory()->create([LeadPhase::fkColumn('lead_board') => $lead->{Lead::fkColumn('lead_board')}]);

    $this->actingAs($user);
    $lead->moveToPhase($target);

    $activity = $lead->activities()->where('type', LeadActivityTypeEnum::Moved->value)->latest()->first();
    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->causer_type)->toBe(config('lead-pipeline.user_model'))
        // the phase move itself is still recorded correctly
        ->and($activity->properties['new_phase'])->toBe($target->getKey());
});
