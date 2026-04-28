<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardSharedTenant;

it('casts permissions to array', function (): void {
    $board = LeadBoard::factory()->create();

    $share = LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => (string) Str::uuid(),
        'permissions'      => ['stats', 'leads_readonly'],
    ]);

    $share->refresh();

    expect($share->permissions)->toBe(['stats', 'leads_readonly']);
});

it('belongs to a lead board', function (): void {
    $board = LeadBoard::factory()->create();

    $share = LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => (string) Str::uuid(),
        'permissions'      => null,
    ]);

    expect($share->board)->toBeInstanceOf(LeadBoard::class)
        ->and($share->board->uuid)->toBe($board->uuid);
});

it('exposes a polymorphic sharedWith relation', function (): void {
    $board    = LeadBoard::factory()->create();
    $tenantId = (string) Str::uuid();

    $share = LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => $tenantId,
        'permissions'      => null,
    ]);

    expect($share->shared_with_type)->toBe('App\\Models\\Team')
        ->and($share->shared_with_id)->toBe($tenantId);
});
