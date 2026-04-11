<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('lead_sources', function (Blueprint $table): void {
            $table->string('created_by')->nullable()->index()->after('driver');
        });
    }

    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table): void {
            $table->dropColumn('created_by');
        });
    }
};
