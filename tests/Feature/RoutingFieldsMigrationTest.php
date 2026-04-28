<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

it('adds routing_mode column with manual default to lead_boards', function (): void {
    expect(Schema::hasColumn('lead_boards', 'routing_mode'))->toBeTrue();

    $board = LeadBoard::factory()->create();

    expect($board->fresh()->routing_mode)->toBe('manual');
});

it('adds nullable recipient morph columns to lead_boards', function (): void {
    expect(Schema::hasColumn('lead_boards', 'recipient_type'))->toBeTrue()
        ->and(Schema::hasColumn('lead_boards', 'recipient_id'))->toBeTrue();

    $board = LeadBoard::factory()->create();

    expect($board->recipient_type)->toBeNull()
        ->and($board->recipient_id)->toBeNull();
});

it('adds nullable routing_settings json column to lead_boards', function (): void {
    expect(Schema::hasColumn('lead_boards', 'routing_settings'))->toBeTrue();

    $board = LeadBoard::factory()->create();

    expect($board->routing_settings)->toBeNull();
});
