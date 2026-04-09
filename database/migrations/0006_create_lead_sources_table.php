<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();
                $table->uuid('lead_board_uuid')->index();
            } else {
                $table->id();
                $table->unsignedBigInteger('lead_board_id')->index();
            }

            // Team FK – nur wenn Tenancy aktiv
            if (config('lead-pipeline.tenancy.enabled', true)) {
                $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
                if (str_contains($teamFk, 'uuid')) {
                    $table->uuid($teamFk)->nullable()->index();
                } else {
                    $table->unsignedBigInteger($teamFk)->nullable()->index();
                }
            }

            $table->string('name');
            $table->string('driver');
            $table->string('status')->default('draft');
            $table->json('config')->nullable();
            $table->string('api_token', 64)->nullable();
            $table->string('webhook_secret', 64)->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
