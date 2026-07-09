<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-healing migration for the Meta analytics tables (originally created by
 * migrations 0027–0029).
 *
 * Some deployments were seeded/restored from a database snapshot that carried
 * the `migrations` ledger but not these physical tables — so `migrate` reports
 * "Nothing to migrate" while the tables are actually absent, and any code that
 * reads them (LeadOperations ad-cost, Meta reports) fails with a 42S02. This
 * migration recreates each table only when it is missing, so a normal deploy
 * reconciles the drift on every environment. It is idempotent: where the tables
 * already exist, every block is skipped.
 */
return new class() extends Migration {
    public function up(): void
    {
        $tenantFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

        if ( ! Schema::hasTable('meta_insight_snapshots')) {
            Schema::create('meta_insight_snapshots', function (Blueprint $table) use ($tenantFk): void {
                $table->uuid('uuid')->primary();
                $table->string($tenantFk)->index();
                $table->string('ad_account_id', 64);
                $table->string('campaign_id', 64)->nullable();
                $table->string('campaign_name')->nullable();
                $table->date('date');
                $table->string('breakdown_type', 16)->default('none');
                $table->string('breakdown_value', 32)->default('');
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('reach')->default(0);
                $table->decimal('spend', 12, 2)->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->unsignedBigInteger('link_clicks')->default(0);
                $table->unsignedBigInteger('leads')->default(0);
                $table->timestamps();

                $table->unique(['ad_account_id', 'campaign_id', 'date', 'breakdown_type', 'breakdown_value'], 'meta_insight_snapshots_upsert_key');
                $table->index(['ad_account_id', 'date']);
            });
        }

        if ( ! Schema::hasTable('meta_reach_ranges')) {
            Schema::create('meta_reach_ranges', function (Blueprint $table): void {
                $table->uuid('uuid')->primary();
                $table->string('ad_account_id', 64);
                $table->string('campaign_key', 64)->default('');
                $table->string('preset', 32);
                $table->date('date_from');
                $table->date('date_till');
                $table->unsignedBigInteger('reach')->default(0);
                $table->timestamp('fetched_at');
                $table->timestamps();

                $table->unique(['ad_account_id', 'campaign_key', 'preset', 'date_from', 'date_till'], 'meta_reach_ranges_lookup_key');
                $table->index(['ad_account_id', 'date_from', 'date_till']);
            });
        }

        if ( ! Schema::hasTable('meta_ad_creatives')) {
            Schema::create('meta_ad_creatives', function (Blueprint $table) use ($tenantFk): void {
                $table->uuid('uuid')->primary();
                $table->string($tenantFk)->index();
                $table->string('ad_account_id', 64);
                $table->string('campaign_id', 64)->nullable();
                $table->string('ad_id', 64)->unique();
                $table->string('name')->nullable();
                $table->string('status')->nullable();
                $table->string('image_path')->nullable();
                $table->unsignedBigInteger('lifetime_impressions')->default(0);
                $table->unsignedBigInteger('lifetime_leads')->default(0);
                $table->decimal('lifetime_spend', 12, 2)->default(0);
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->index(['ad_account_id', 'campaign_id']);
            });
        }
    }

    /**
     * Intentionally a no-op: this migration only recreates tables that were
     * missing. Dropping is owned by the original create migrations (0027–0029)
     * so a rollback here never destroys real analytics data.
     */
    public function down(): void {}
};
