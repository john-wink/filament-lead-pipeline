<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();

                $table->uuid('lead_uuid');
                $table->foreign('lead_uuid')
                    ->references('uuid')
                    ->on('leads')
                    ->cascadeOnDelete();
            } else {
                $table->id();

                $table->unsignedBigInteger('lead_id');
                $table->foreign('lead_id')
                    ->references('id')
                    ->on('leads')
                    ->cascadeOnDelete();
            }

            $table->string('type');
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            if ($isUuid) {
                $table->nullableUuidMorphs('causer');
            } else {
                $table->nullableMorphs('causer');
            }
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
