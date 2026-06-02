<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $sourceFk = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid')
            ? 'lead_source_uuid'
            : 'lead_source_id';

        Schema::table('leads', function (Blueprint $table) use ($sourceFk): void {
            $table->string('external_id')->nullable()->after($sourceFk);
            $table->unique([$sourceFk, 'external_id'], 'leads_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropUnique('leads_source_external_unique');
            $table->dropColumn('external_id');
        });
    }
};
