<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('source_campaign_id', 64)->nullable();
            $table->string('source_campaign_name', 255)->nullable();
            $table->string('source_adgroup_id', 64)->nullable();
            $table->string('source_adgroup_name', 255)->nullable();
            $table->string('source_ad_id', 64)->nullable();
            $table->string('source_ad_name', 255)->nullable();
            $table->string('source_channel', 32)->nullable();

            $table->index('source_campaign_id');
            $table->index('source_adgroup_id');
            $table->index('source_ad_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['source_campaign_id']);
            $table->dropIndex(['source_adgroup_id']);
            $table->dropIndex(['source_ad_id']);
            $table->dropColumn([
                'source_campaign_id',
                'source_campaign_name',
                'source_adgroup_id',
                'source_adgroup_name',
                'source_ad_id',
                'source_ad_name',
                'source_channel',
            ]);
        });
    }
};
