<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $isUuid   = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');
        $pkCol    = $isUuid ? 'uuid' : 'id';
        $boardFk  = $isUuid ? 'lead_board_uuid' : 'lead_board_id';
        $sourceFk = $isUuid ? 'lead_source_uuid' : 'lead_source_id';

        // 1. Remove/null orphaned rows so the constraints can be created on a
        //    database that already holds data. No-op on a fresh database.
        //    Order matters: clean parents-of-parents first.
        DB::table('facebook_pages')
            ->whereNotIn('facebook_connection_uuid', fn ($q) => $q->select('uuid')->from('facebook_connections'))
            ->delete();

        DB::table('facebook_forms')
            ->whereNotIn('facebook_page_uuid', fn ($q) => $q->select('uuid')->from('facebook_pages'))
            ->delete();

        DB::table('lead_sources')
            ->whereNotNull('facebook_page_uuid')
            ->whereNotIn('facebook_page_uuid', fn ($q) => $q->select('uuid')->from('facebook_pages'))
            ->update(['facebook_page_uuid' => null]);

        DB::table('lead_sources')
            ->whereNotIn($boardFk, fn ($q) => $q->select($pkCol)->from('lead_boards'))
            ->delete();

        DB::table('leads')
            ->whereNotNull($sourceFk)
            ->whereNotIn($sourceFk, fn ($q) => $q->select($pkCol)->from('lead_sources'))
            ->update([$sourceFk => null]);

        // 2. Add foreign-key constraints.
        Schema::table('facebook_pages', function (Blueprint $table): void {
            $table->foreign('facebook_connection_uuid')
                ->references('uuid')->on('facebook_connections')
                ->cascadeOnDelete();
        });

        Schema::table('facebook_forms', function (Blueprint $table): void {
            $table->foreign('facebook_page_uuid')
                ->references('uuid')->on('facebook_pages')
                ->cascadeOnDelete();
        });

        Schema::table('lead_sources', function (Blueprint $table) use ($boardFk, $pkCol): void {
            $table->foreign($boardFk)
                ->references($pkCol)->on('lead_boards')
                ->cascadeOnDelete();
            $table->foreign('facebook_page_uuid')
                ->references('uuid')->on('facebook_pages')
                ->nullOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) use ($sourceFk, $pkCol): void {
            $table->foreign($sourceFk)
                ->references($pkCol)->on('lead_sources')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $isUuid   = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');
        $boardFk  = $isUuid ? 'lead_board_uuid' : 'lead_board_id';
        $sourceFk = $isUuid ? 'lead_source_uuid' : 'lead_source_id';

        Schema::table('leads', fn (Blueprint $table) => $table->dropForeign([$sourceFk]));
        Schema::table('lead_sources', function (Blueprint $table) use ($boardFk): void {
            $table->dropForeign([$boardFk]);
            $table->dropForeign(['facebook_page_uuid']);
        });
        Schema::table('facebook_forms', fn (Blueprint $table) => $table->dropForeign(['facebook_page_uuid']));
        Schema::table('facebook_pages', fn (Blueprint $table) => $table->dropForeign(['facebook_connection_uuid']));
    }
};
