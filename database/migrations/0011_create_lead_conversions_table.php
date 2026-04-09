<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_conversions', function (Blueprint $table): void {
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

            $table->string('convertible_type');
            $table->string('convertible_id');
            $table->string('converter_class');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['convertible_type', 'convertible_id'], 'lead_conversions_convertible_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_conversions');
    }
};
