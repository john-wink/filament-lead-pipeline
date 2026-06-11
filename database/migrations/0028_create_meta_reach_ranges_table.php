<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('meta_reach_ranges', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('ad_account_id');
            $table->string('campaign_key')->default('');
            $table->string('preset');
            $table->date('date_from');
            $table->date('date_till');
            $table->unsignedBigInteger('reach')->default(0);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['ad_account_id', 'campaign_key', 'preset', 'date_from', 'date_till'], 'meta_reach_ranges_lookup_key');
            $table->index(['ad_account_id', 'date_from', 'date_till']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_reach_ranges');
    }
};
