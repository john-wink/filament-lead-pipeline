<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_field_definitions', function (Blueprint $table): void {
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
            $table->string('key');
            $table->string('type');
            $table->json('options')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('show_in_card')->default(false);
            $table->boolean('show_in_funnel')->default(true);
            $table->integer('sort')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique Key pro Board
            $boardFk = $isUuid ? 'lead_board_uuid' : 'lead_board_id';
            $table->unique([$boardFk, 'key'], 'lead_field_definitions_board_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_field_definitions');
    }
};
