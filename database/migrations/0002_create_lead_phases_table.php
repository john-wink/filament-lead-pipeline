<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_phases', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();
                $table->uuid('lead_board_uuid');
                $table->foreign('lead_board_uuid')
                    ->references('uuid')
                    ->on('lead_boards')
                    ->cascadeOnDelete();
            } else {
                $table->id();
                $table->unsignedBigInteger('lead_board_id');
                $table->foreign('lead_board_id')
                    ->references('id')
                    ->on('lead_boards')
                    ->cascadeOnDelete();
            }

            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->string('type')->default('in_progress');
            $table->string('display_type')->default('kanban');
            $table->integer('sort')->default(0);
            $table->boolean('auto_convert')->default(false);
            $table->string('conversion_target')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_phases');
    }
};
