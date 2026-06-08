<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_board_team_shares', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('owner_team_id');
            $table->string('shared_with_type')->nullable();
            $table->uuid('shared_with_id')->nullable();
            $table->string('shared_with_relation')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->index('owner_team_id', 'lead_board_team_shares_owner_idx');
            $table->index(['shared_with_type', 'shared_with_id'], 'lead_board_team_shares_shared_with_idx');
            $table->index('shared_with_relation', 'lead_board_team_shares_relation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_board_team_shares');
    }
};
