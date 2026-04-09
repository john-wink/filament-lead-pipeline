<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('facebook_connections', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();

            $userFk   = config('lead-pipeline.user_foreign_key', 'user_uuid');
            $tenantFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

            $table->string($userFk);
            $table->string($tenantFk);
            $table->string('facebook_user_id');
            $table->string('facebook_user_name')->nullable();
            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status')->default('connected');
            $table->timestamps();

            $table->index([$userFk, $tenantFk]);
            $table->unique([$userFk, 'facebook_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_connections');
    }
};
