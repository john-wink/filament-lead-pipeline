<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasColumn('lead_sources', 'facebook_page_id')) {
            Schema::table('lead_sources', function (Blueprint $table): void {
                $table->string('facebook_page_id')->nullable()->index();
            });
        }

        // Backfill: stamp the stable external page id onto already linked
        // sources so a later disconnect/reconnect cycle can relink them.
        DB::table('lead_sources')
            ->whereNotNull('facebook_page_uuid')
            ->whereNull('facebook_page_id')
            ->update([
                'facebook_page_id' => DB::raw('(select page_id from facebook_pages where facebook_pages.uuid = lead_sources.facebook_page_uuid)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table): void {
            $table->dropColumn('facebook_page_id');
        });
    }
};
