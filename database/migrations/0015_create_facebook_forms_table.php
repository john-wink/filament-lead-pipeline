<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('facebook_forms', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('facebook_page_uuid');
            $table->string('form_id');
            $table->string('form_name');
            $table->string('status')->default('active');
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();

            $table->unique(['facebook_page_uuid', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_forms');
    }
};
