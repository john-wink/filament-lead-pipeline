<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaCreativesJob;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaInsightsJob;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportAdSource;

it('dispatches one insights and one creatives job per distinct ad account of active reports', function (): void {
    Queue::fake();
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    $reportA  = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $reportB  = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $inactive = LeadReport::factory()->create(['team_uuid' => $team->uuid, 'is_active' => false]);

    $connection = JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    LeadReportAdSource::factory()->create(['report_uuid' => $reportA->uuid, 'facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_1']);
    LeadReportAdSource::factory()->create(['report_uuid' => $reportB->uuid, 'facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_1']); // gleiches Konto → dedupliziert
    LeadReportAdSource::factory()->create(['report_uuid' => $reportB->uuid, 'facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_2']);
    LeadReportAdSource::factory()->create(['report_uuid' => $inactive->uuid, 'facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_3']);

    $this->artisan('lead-pipeline:sync-meta-reports')->assertSuccessful();

    Queue::assertPushed(SyncMetaInsightsJob::class, 2);
    Queue::assertPushed(SyncMetaCreativesJob::class, 2);
    Queue::assertNotPushed(SyncMetaInsightsJob::class, fn (SyncMetaInsightsJob $job): bool => 'act_3' === $job->adAccountId);
});

it('supports a days option for the hourly light sync', function (): void {
    Queue::fake();
    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report     = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $connection = JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    LeadReportAdSource::factory()->create(['report_uuid' => $report->uuid, 'facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_1']);

    $this->artisan('lead-pipeline:sync-meta-reports', ['--days' => 1, '--skip-creatives' => true])->assertSuccessful();

    Queue::assertPushed(SyncMetaInsightsJob::class, fn (SyncMetaInsightsJob $job): bool => 1 === $job->days);
    Queue::assertNotPushed(SyncMetaCreativesJob::class);
});

it('marks ad sources as ok with last_synced_at after a successful sync run', function (): void {
    // Gehört logisch zu Task 5 (Job), wird aber erst hier grün: lead_report_ad_sources entsteht in Task 7.
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [], 'paging' => []])]);

    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report     = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $connection = JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    $source     = LeadReportAdSource::factory()->create([
        'report_uuid'              => $report->uuid,
        'facebook_connection_uuid' => $connection->uuid,
        'ad_account_id'            => 'act_1',
        'sync_status'              => 'pending',
    ]);

    (new SyncMetaInsightsJob($connection->uuid, 'act_1', null, 7))->handle(
        app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class),
    );

    $source->refresh();
    expect($source->sync_status)->toBe('ok')
        ->and($source->last_synced_at)->not->toBeNull();
});
