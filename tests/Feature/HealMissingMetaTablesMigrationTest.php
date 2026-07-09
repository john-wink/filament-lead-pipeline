<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

function healMigration(): object
{
    return require dirname(__DIR__, 2) . '/database/migrations/0037_heal_missing_meta_tables.php';
}

it('recreates the meta tables when they are missing', function (): void {
    Schema::drop('meta_insight_snapshots');
    Schema::drop('meta_reach_ranges');
    Schema::drop('meta_ad_creatives');

    expect(Schema::hasTable('meta_insight_snapshots'))->toBeFalse();

    healMigration()->up();

    expect(Schema::hasTable('meta_insight_snapshots'))->toBeTrue()
        ->and(Schema::hasColumn('meta_insight_snapshots', 'spend'))->toBeTrue()
        ->and(Schema::hasTable('meta_reach_ranges'))->toBeTrue()
        ->and(Schema::hasTable('meta_ad_creatives'))->toBeTrue();
});

it('is idempotent when the tables already exist', function (): void {
    expect(Schema::hasTable('meta_insight_snapshots'))->toBeTrue();

    // Must not throw "table already exists" — every block is guarded by hasTable.
    healMigration()->up();

    expect(Schema::hasTable('meta_insight_snapshots'))->toBeTrue();
});
