<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_board_admins', function (Blueprint $table): void {
            $pkType = config('lead-pipeline.primary_key_type', 'uuid');
            $userFk = config('lead-pipeline.user_foreign_key', 'user_uuid');

            $table->id();

            if ('uuid' === $pkType) {
                $table->foreignUuid('lead_board_uuid')->constrained('lead_boards', 'uuid')->cascadeOnDelete();
            } else {
                $table->foreignId('lead_board_id')->constrained('lead_boards')->cascadeOnDelete();
            }

            $table->string($userFk);
            $table->timestamps();

            $table->unique(['uuid' === $pkType ? 'lead_board_uuid' : 'lead_board_id', $userFk]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_board_admins');
    }
};
