<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_field_values', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();

                $table->uuid('lead_uuid');
                $table->foreign('lead_uuid')
                    ->references('uuid')
                    ->on('leads')
                    ->cascadeOnDelete();

                $table->uuid('lead_field_definition_uuid');
                $table->foreign('lead_field_definition_uuid')
                    ->references('uuid')
                    ->on('lead_field_definitions')
                    ->cascadeOnDelete();

                $table->unique(
                    ['lead_uuid', 'lead_field_definition_uuid'],
                    'lead_field_values_lead_definition_unique',
                );
            } else {
                $table->id();

                $table->unsignedBigInteger('lead_id');
                $table->foreign('lead_id')
                    ->references('id')
                    ->on('leads')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('lead_field_definition_id');
                $table->foreign('lead_field_definition_id')
                    ->references('id')
                    ->on('lead_field_definitions')
                    ->cascadeOnDelete();

                $table->unique(
                    ['lead_id', 'lead_field_definition_id'],
                    'lead_field_values_lead_definition_unique',
                );
            }

            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_field_values');
    }
};
