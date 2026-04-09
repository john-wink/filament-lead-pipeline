<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_funnel_step_fields', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();

                $table->uuid('lead_funnel_step_uuid');
                $table->foreign('lead_funnel_step_uuid')
                    ->references('uuid')
                    ->on('lead_funnel_steps')
                    ->cascadeOnDelete();

                $table->uuid('lead_field_definition_uuid');
                $table->foreign('lead_field_definition_uuid')
                    ->references('uuid')
                    ->on('lead_field_definitions')
                    ->cascadeOnDelete();
            } else {
                $table->id();

                $table->unsignedBigInteger('lead_funnel_step_id');
                $table->foreign('lead_funnel_step_id')
                    ->references('id')
                    ->on('lead_funnel_steps')
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('lead_field_definition_id');
                $table->foreign('lead_field_definition_id')
                    ->references('id')
                    ->on('lead_field_definitions')
                    ->cascadeOnDelete();
            }

            $table->integer('sort')->default(0);
            $table->boolean('is_required')->default(false);
            $table->string('placeholder')->nullable();
            $table->string('help_text')->nullable();
            $table->string('funnel_field_type')->nullable();
            $table->json('funnel_options')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_funnel_step_fields');
    }
};
