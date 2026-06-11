<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $userFk = config('lead-pipeline.user_foreign_key', 'user_uuid');

        Schema::create('lead_reports', function (Blueprint $table) use ($teamFk, $userFk): void {
            $table->uuid('uuid')->primary();
            $table->string($teamFk)->index();
            $table->string($userFk)->nullable();
            $table->string('name');
            $table->string('share_token', 64)->unique();
            $table->string('password')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('date_preset_default')->default('last30days');
            $table->boolean('date_locked')->default(false);
            $table->json('funnel_mapping')->nullable();
            $table->json('sections')->nullable();
            $table->json('branding_settings')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lead_report_boards', function (Blueprint $table): void {
            $table->uuid('report_uuid');
            $table->uuid('board_uuid');
            $table->primary(['report_uuid', 'board_uuid']);

            // FK setzt uuid-Modus (primary_key_type=uuid) voraus — Präzedenzfall: Migrationen 0013/0026
            $table->foreign('report_uuid')->references('uuid')->on('lead_reports')->cascadeOnDelete();
            $table->foreign('board_uuid')->references('uuid')->on('lead_boards')->cascadeOnDelete();
        });

        Schema::create('lead_report_ad_sources', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('report_uuid')->index();
            $table->uuid('facebook_connection_uuid');
            $table->string('ad_account_id');
            $table->json('campaign_ids')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('report_uuid')->references('uuid')->on('lead_reports')->cascadeOnDelete();
            $table->foreign('facebook_connection_uuid')->references('uuid')->on('facebook_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_report_ad_sources');
        Schema::dropIfExists('lead_report_boards');
        Schema::dropIfExists('lead_reports');
    }
};
