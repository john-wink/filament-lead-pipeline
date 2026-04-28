<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

it('creates lead_board_stats table with required columns', function (): void {
    expect(Schema::hasTable('lead_board_stats'))->toBeTrue();

    expect(Schema::hasColumns('lead_board_stats', [
        'uuid',
        'lead_board_uuid',
        'period_date',
        'counts',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('stores counts payload as json', function (): void {
    $board = LeadBoard::factory()->create();

    DB::table('lead_board_stats')->insert([
        'uuid'            => (string) Str::uuid(),
        'lead_board_uuid' => $board->uuid,
        'period_date'     => now()->toDateString(),
        'counts'          => json_encode(['total' => 10, 'won' => 2, 'lost' => 3]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $row = DB::table('lead_board_stats')->first();

    expect(json_decode($row->counts, true))
        ->toBe(['total' => 10, 'won' => 2, 'lost' => 3]);
});

it('rejects duplicate (board, period_date) combinations', function (): void {
    $board = LeadBoard::factory()->create();
    $today = now()->toDateString();

    DB::table('lead_board_stats')->insert([
        'uuid'            => (string) Str::uuid(),
        'lead_board_uuid' => $board->uuid,
        'period_date'     => $today,
        'counts'          => json_encode(['total' => 1]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    expect(fn () => DB::table('lead_board_stats')->insert([
        'uuid'            => (string) Str::uuid(),
        'lead_board_uuid' => $board->uuid,
        'period_date'     => $today,
        'counts'          => json_encode(['total' => 99]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('cascades deletes from lead_board to stats', function (): void {
    $board = LeadBoard::factory()->create();
    DB::table('lead_board_stats')->insert([
        'uuid'            => (string) Str::uuid(),
        'lead_board_uuid' => $board->uuid,
        'period_date'     => now()->toDateString(),
        'counts'          => json_encode(['total' => 0]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    expect(DB::table('lead_board_stats')->count())->toBe(1);

    $board->forceDelete();

    expect(DB::table('lead_board_stats')->count())->toBe(0);
});
