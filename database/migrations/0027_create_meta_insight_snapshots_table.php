<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Index-Spalten gekürzt: 4 varchar(255) im Unique-Index überschreiten MySQLs 3072-Byte-Limit
        Schema::create('meta_insight_snapshots', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'))->index();
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

    public function down(): void
    {
        Schema::dropIfExists('meta_insight_snapshots');
    }
};
