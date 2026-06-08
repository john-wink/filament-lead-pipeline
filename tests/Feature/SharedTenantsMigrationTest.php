<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

it('creates lead_board_shared_tenants table with required columns', function (): void {
    expect(Schema::hasTable('lead_board_shared_tenants'))->toBeTrue();

    expect(Schema::hasColumns('lead_board_shared_tenants', [
        'lead_board_uuid',
        'shared_with_type',
        'shared_with_id',
        'permissions',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('creates lead_board_team_shares table with required columns', function (): void {
    expect(Schema::hasTable('lead_board_team_shares'))->toBeTrue();

    expect(Schema::hasColumns('lead_board_team_shares', [
        'uuid',
        'owner_team_id',
        'shared_with_type',
        'shared_with_id',
        'shared_with_relation',
        'permissions',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('allows null permissions when sharing a lead_board with a tenant', function (): void {
    $board = LeadBoard::factory()->create();

    DB::table('lead_board_shared_tenants')->insert([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => (string) Str::uuid(),
        'permissions'      => null,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    expect(DB::table('lead_board_shared_tenants')->count())->toBe(1);
});

it('rejects duplicate shared tenant entries via composite primary key', function (): void {
    $board      = LeadBoard::factory()->create();
    $tenantUuid = (string) Str::uuid();

    DB::table('lead_board_shared_tenants')->insert([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => $tenantUuid,
        'permissions'      => json_encode(['stats']),
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    expect(fn () => DB::table('lead_board_shared_tenants')->insert([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => $tenantUuid,
        'permissions'      => json_encode(['leads_readonly']),
        'created_at'       => now(),
        'updated_at'       => now(),
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('cascades deletes from lead_board to shared_tenants', function (): void {
    $board = LeadBoard::factory()->create();
    DB::table('lead_board_shared_tenants')->insert([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => 'App\\Models\\Team',
        'shared_with_id'   => (string) Str::uuid(),
        'permissions'      => null,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    expect(DB::table('lead_board_shared_tenants')->count())->toBe(1);

    $board->forceDelete();

    expect(DB::table('lead_board_shared_tenants')->count())->toBe(0);
});
