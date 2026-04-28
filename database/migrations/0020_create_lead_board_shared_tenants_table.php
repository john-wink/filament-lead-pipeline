<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_board_shared_tenants', function (Blueprint $table): void {
            $table->uuid('lead_board_uuid');
            $table->string('shared_with_type');
            $table->uuid('shared_with_id');
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->primary(['lead_board_uuid', 'shared_with_type', 'shared_with_id'], 'lead_board_shared_tenants_pk');
            $table->index(['shared_with_type', 'shared_with_id'], 'lead_board_shared_tenants_shared_with_idx');

            $table->foreign('lead_board_uuid')
                ->references('uuid')
                ->on('lead_boards')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_board_shared_tenants');
    }
};
