<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;

it('has first-response columns on the leads table', function (): void {
    expect(Schema::hasColumn('leads', 'first_response_at'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'first_response_by'))->toBeTrue();
});

it('casts first_response_at to a datetime', function (): void {
    $lead = Lead::factory()->create(['first_response_at' => now()]);

    expect($lead->refresh()->first_response_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

it('stamps first_response_at on the first call activity', function (): void {
    $lead = Lead::factory()->create(['first_response_at' => null]);

    $activity = $lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Call->value,
        'description' => 'angerufen',
        'causer_id'   => 'user-123',
        'causer_type' => 'App\\Models\\User',
    ]);

    $lead->refresh();
    // Must equal the triggering activity's timestamp, not now() at save time.
    expect($lead->first_response_at->equalTo($activity->created_at))->toBeTrue()
        ->and($lead->first_response_by)->toBe('user-123');
});

it('does not overwrite first_response_at on later contacts', function (): void {
    $lead  = Lead::factory()->create(['first_response_at' => now()->subDays(2), 'first_response_by' => 'first-user']);
    $stamp = $lead->first_response_at;

    $lead->activities()->create(['type' => LeadActivityTypeEnum::Email->value, 'causer_id' => 'second-user']);

    $lead->refresh();
    expect($lead->first_response_at->equalTo($stamp))->toBeTrue()
        ->and($lead->first_response_by)->toBe('first-user');
});

it('ignores non-contact activity types', function (): void {
    $lead = Lead::factory()->create(['first_response_at' => null]);

    $lead->activities()->create(['type' => LeadActivityTypeEnum::Note->value, 'description' => 'nur eine Notiz']);

    expect($lead->refresh()->first_response_at)->toBeNull()
        ->and($lead->first_response_by)->toBeNull();
});
