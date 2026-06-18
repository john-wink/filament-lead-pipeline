<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_webhook_logs', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();
            } else {
                $table->id();
            }

            if (config('lead-pipeline.tenancy.enabled', true)) {
                $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
                if (str_contains($teamFk, 'uuid')) {
                    $table->uuid($teamFk)->nullable()->index();
                } else {
                    $table->unsignedBigInteger($teamFk)->nullable()->index();
                }
            }

            $table->uuid('lead_source_uuid')->nullable()->index();
            $table->uuid('facebook_page_uuid')->nullable()->index();
            $table->string('page_id')->nullable();
            $table->uuid('lead_uuid')->nullable();
            $table->string('event_type')->index();
            $table->string('driver')->nullable();
            $table->string('outcome')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('message')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_webhook_logs');
    }
};
