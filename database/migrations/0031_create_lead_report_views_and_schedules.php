<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_report_views', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('report_uuid');
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['report_uuid', 'date']);
            $table->foreign('report_uuid')->references('uuid')->on('lead_reports')->cascadeOnDelete();
        });

        Schema::create('lead_report_schedules', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('report_uuid')->index();
            $table->string('frequency');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->time('send_time')->default('08:00');
            $table->json('recipients');
            $table->boolean('attach_pdf')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->foreign('report_uuid')->references('uuid')->on('lead_reports')->cascadeOnDelete();
        });

        Schema::create('lead_report_sends', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('schedule_uuid');
            $table->timestamp('sent_at');
            $table->json('recipients');
            $table->boolean('pdf_attached')->default(false);
            $table->string('status')->default('sent');
            $table->timestamps();

            $table->foreign('schedule_uuid')->references('uuid')->on('lead_report_schedules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_report_sends');
        Schema::dropIfExists('lead_report_schedules');
        Schema::dropIfExists('lead_report_views');
    }
};
