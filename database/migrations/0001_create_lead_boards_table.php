<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_boards', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();
            } else {
                $table->id();
            }

            // Team FK – konfigurierbar über Tenancy-Config
            if (config('lead-pipeline.tenancy.enabled', true)) {
                $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
                if (str_contains($teamFk, 'uuid')) {
                    $table->uuid($teamFk)->nullable()->index();
                } else {
                    $table->unsignedBigInteger($teamFk)->nullable()->index();
                }
            }

            $table->string('name');
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_boards');
    }
};
