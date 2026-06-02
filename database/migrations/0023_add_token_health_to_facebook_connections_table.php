<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('facebook_connections', function (Blueprint $table): void {
            $table->timestamp('last_refreshed_at')->nullable()->after('token_expires_at');
            $table->timestamp('acquired_at')->nullable()->after('last_refreshed_at');
            $table->unsignedTinyInteger('refresh_attempts')->default(0)->after('acquired_at');
            $table->timestamp('refresh_failed_at')->nullable()->after('refresh_attempts');
            $table->text('last_error')->nullable()->after('refresh_failed_at');
            $table->timestamp('expiring_soon_notified_at')->nullable()->after('last_error');
        });

        // Migrate legacy status value 'expired' → 'needs_reauth'.
        FacebookConnection::query()
            ->where('status', 'expired')
            ->update(['status' => 'needs_reauth']);
    }

    public function down(): void
    {
        Schema::table('facebook_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'last_refreshed_at',
                'acquired_at',
                'refresh_attempts',
                'refresh_failed_at',
                'last_error',
                'expiring_soon_notified_at',
            ]);
        });
    }
};
