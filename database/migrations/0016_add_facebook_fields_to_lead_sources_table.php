<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('lead_sources', function (Blueprint $table): void {
            $table->uuid('facebook_page_uuid')->nullable();
            $table->json('facebook_form_ids')->nullable();
            $table->string('default_assigned_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table): void {
            $table->dropColumn(['facebook_page_uuid', 'facebook_form_ids', 'default_assigned_to']);
        });
    }
};
