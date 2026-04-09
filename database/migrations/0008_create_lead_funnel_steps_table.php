<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_funnel_steps', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();

                $table->uuid('lead_funnel_uuid');
                $table->foreign('lead_funnel_uuid')
                    ->references('uuid')
                    ->on('lead_funnels')
                    ->cascadeOnDelete();
            } else {
                $table->id();

                $table->unsignedBigInteger('lead_funnel_id');
                $table->foreign('lead_funnel_id')
                    ->references('id')
                    ->on('lead_funnels')
                    ->cascadeOnDelete();
            }

            $table->string('name');
            $table->string('step_type')->default('form');
            $table->text('description')->nullable();
            $table->integer('sort')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_funnel_steps');
    }
};
