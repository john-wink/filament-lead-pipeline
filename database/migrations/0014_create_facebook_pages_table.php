<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('facebook_pages', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('facebook_connection_uuid');
            $table->string('page_id');
            $table->string('page_name');
            $table->text('page_access_token');
            $table->boolean('is_webhooks_subscribed')->default(false);
            $table->timestamps();

            $table->unique(['facebook_connection_uuid', 'page_id']);
            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_pages');
    }
};
