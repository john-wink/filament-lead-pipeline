<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();

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

                $table->uuid('lead_source_uuid')->nullable()->index();
                $table->uuid('assigned_to')->nullable()->index();
            } else {
                $table->id();

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

                $table->unsignedBigInteger('lead_source_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
            }

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('status')->default('active');
            $table->integer('sort')->default(0);
            $table->decimal('value', 12, 2)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
