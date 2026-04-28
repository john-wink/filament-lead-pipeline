<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_board_stats', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('lead_board_uuid');
            $table->date('period_date');
            $table->json('counts');
            $table->timestamps();

            $table->unique(['lead_board_uuid', 'period_date'], 'lead_board_stats_board_period_unique');
            $table->index('period_date', 'lead_board_stats_period_idx');

            $table->foreign('lead_board_uuid')
                ->references('uuid')
                ->on('lead_boards')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_board_stats');
    }
};
