<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\Lead;

it('has first-response columns on the leads table', function (): void {
    expect(Schema::hasColumn('leads', 'first_response_at'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'first_response_by'))->toBeTrue();
});

it('casts first_response_at to a datetime', function (): void {
    $lead = Lead::factory()->create(['first_response_at' => now()]);

    expect($lead->refresh()->first_response_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});
