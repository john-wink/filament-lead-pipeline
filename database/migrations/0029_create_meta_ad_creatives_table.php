<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('meta_ad_creatives', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'))->index();
            $table->string('ad_account_id');
            $table->string('campaign_id')->nullable();
            $table->string('ad_id')->unique();
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

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_creatives');
    }
};
