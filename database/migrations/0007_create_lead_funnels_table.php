<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_funnels', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();

                $table->uuid('lead_source_uuid');
                $table->foreign('lead_source_uuid')
                    ->references('uuid')
                    ->on('lead_sources')
                    ->cascadeOnDelete();

                $table->uuid('lead_board_uuid');
                $table->foreign('lead_board_uuid')
                    ->references('uuid')
                    ->on('lead_boards')
                    ->cascadeOnDelete();

                $table->uuid('lead_phase_uuid')->nullable();
                $table->foreign('lead_phase_uuid')
                    ->references('uuid')
                    ->on('lead_phases')
                    ->nullOnDelete();
            } else {
                $table->id();

                $table->unsignedBigInteger('lead_source_id');
                $table->foreign('lead_source_id')
                    ->references('id')
                    ->on('lead_sources')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('lead_board_id');
                $table->foreign('lead_board_id')
                    ->references('id')
                    ->on('lead_boards')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('lead_phase_id')->nullable();
                $table->foreign('lead_phase_id')
                    ->references('id')
                    ->on('lead_phases')
                    ->nullOnDelete();
            }

            $table->string('name');
            $table->string('slug')->unique();
            $table->json('design');
            $table->json('success_config')->nullable();
            $table->json('rejection_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_funnels');
    }
};
