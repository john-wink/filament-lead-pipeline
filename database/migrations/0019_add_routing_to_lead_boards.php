<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('lead_boards', function (Blueprint $table): void {
            $table->string('routing_mode', 16)->default('manual')->after('settings');
            $table->nullableMorphs('recipient');
            $table->json('routing_settings')->nullable()->after('recipient_id');
        });
    }

    public function down(): void
    {
        Schema::table('lead_boards', function (Blueprint $table): void {
            $table->dropMorphs('recipient');
            $table->dropColumn(['routing_mode', 'routing_settings']);
        });
    }
};
