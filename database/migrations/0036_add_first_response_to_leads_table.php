<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

        Schema::table('leads', function (Blueprint $table) use ($isUuid): void {
            $table->timestamp('first_response_at')->nullable()->after('reminder_notified_at');

            if ($isUuid) {
                $table->uuid('first_response_by')->nullable()->after('first_response_at');
            } else {
                $table->unsignedBigInteger('first_response_by')->nullable()->after('first_response_at');
            }

            $table->index('first_response_at');
        });

        Schema::table('lead_activities', function (Blueprint $table) use ($isUuid): void {
            $leadFk = $isUuid ? 'lead_uuid' : 'lead_id';
            $table->index([$leadFk, 'type'], 'lead_activities_lead_type_index');
            $table->index(['type', 'created_at'], 'lead_activities_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('lead_activities', function (Blueprint $table): void {
            $table->dropIndex('lead_activities_lead_type_index');
            $table->dropIndex('lead_activities_type_created_index');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['first_response_at']);
            $table->dropColumn(['first_response_at', 'first_response_by']);
        });
    }
};
