<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('immoscout_connections', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();

            $userFk   = config('lead-pipeline.user_foreign_key', 'user_uuid');
            $tenantFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

            $table->string($userFk);
            $table->string($tenantFk);
            $table->string('name');
            $table->text('consumer_key');
            $table->text('consumer_secret');
            $table->text('access_token')->nullable();
            $table->text('access_token_secret')->nullable();
            $table->string('scout_id')->nullable();
            $table->string('environment')->default('production');
            $table->string('status')->default('connected');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index([$userFk, $tenantFk]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immoscout_connections');
    }
};
