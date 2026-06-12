<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('reminder_at')->nullable()->after('lost_reason');
            $table->string('reminder_note')->nullable()->after('reminder_at');
            $table->timestamp('reminder_notified_at')->nullable()->after('reminder_note');

            $table->index('reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['reminder_at']);
            $table->dropColumn(['reminder_at', 'reminder_note', 'reminder_notified_at']);
        });
    }
};
