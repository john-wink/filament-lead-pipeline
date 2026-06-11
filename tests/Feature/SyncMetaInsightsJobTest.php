<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaInsightsJob;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;
use JohnWink\FilamentLeadPipeline\Models\MetaReachRange;

function fakeInsightsApi(): void
{
    Http::fake([
        'graph.facebook.com/*/act_123/insights*' => function ($request) {
            $url = urldecode($request->url());

            if (str_contains($url, 'breakdowns=gender')) {
                return Http::response(['data' => [[
                    'campaign_id' => 'c1', 'campaign_name' => 'K1',
                    'date_start'  => '2026-06-01', 'date_stop' => '2026-06-01',
                    'impressions' => '900', 'reach' => '700', 'spend' => '30.00',
                    'clicks'      => '50', 'inline_link_clicks' => '40',
                    'actions'     => [['action_type' => 'lead', 'value' => '2']],
                    'gender'      => 'male',
                ]], 'paging' => []]);
            }

            if ( ! str_contains($url, 'time_increment')) {
                // Reach-Range-Abfragen (ohne time_increment)
                return Http::response(['data' => [['reach' => '5000']]]);
            }

            return Http::response(['data' => [[
                'campaign_id' => 'c1', 'campaign_name' => 'K1',
                'date_start'  => '2026-06-01', 'date_stop' => '2026-06-01',
                'impressions' => '1500', 'reach' => '900', 'spend' => '45.67',
                'clicks'      => '80', 'inline_link_clicks' => '60',
                'actions'     => [['action_type' => 'lead', 'value' => '3']],
            ]], 'paging' => []]);
        },
    ]);
}

it('upserts daily snapshots including gender rows and refreshes reach presets', function (): void {
    fakeInsightsApi();
    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $connection = FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);

    (new SyncMetaInsightsJob($connection->uuid, 'act_123', null, 28))->handle(
        app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class),
    );

    $plain = MetaInsightSnapshot::query()->where('breakdown_type', 'none')->first();
    expect($plain)->not->toBeNull()
        ->and((int) $plain->impressions)->toBe(1500)
        ->and((int) $plain->leads)->toBe(3)
        ->and($plain->team_uuid)->toBe($team->uuid);

    expect(MetaInsightSnapshot::query()->where('breakdown_type', 'gender')->where('breakdown_value', 'male')->exists())->toBeTrue();

    // Reach-Presets wurden geschrieben (alle außer Custom)
    expect(MetaReachRange::query()->where('ad_account_id', 'act_123')->count())
        ->toBe(count(ReportDatePresetEnum::cases()) - 1);
});

it('is idempotent — running twice keeps one row per day/campaign/breakdown', function (): void {
    fakeInsightsApi();
    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $connection = FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    $graph      = app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class);

    (new SyncMetaInsightsJob($connection->uuid, 'act_123', null, 28))->handle($graph);
    (new SyncMetaInsightsJob($connection->uuid, 'act_123', null, 28))->handle($graph);

    expect(MetaInsightSnapshot::query()->where('breakdown_type', 'none')->count())->toBe(1);
});

it('releases the job on rate limit errors', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['code' => 17, 'message' => 'User request limit reached'],
    ], 400)]);

    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $connection = FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);

    $job      = new SyncMetaInsightsJob($connection->uuid, 'act_123', null, 28);
    $job->job = Mockery::mock(Illuminate\Contracts\Queue\Job::class)
        ->shouldReceive('release')->once()->with(Mockery::type('int'))
        ->shouldReceive('attempts')->andReturn(1)
        ->shouldReceive('hasFailed')->andReturn(false)
        ->shouldReceive('isReleased')->andReturn(true)
        ->getMock();

    $job->handle(app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class));

    expect(MetaInsightSnapshot::query()->count())->toBe(0);
});

it('releases the job proactively when the usage header reports 80 percent or more', function (): void {
    Http::fake([
        'graph.facebook.com/*/act_123/insights*' => Http::response(['data' => [[
            'campaign_id' => 'c1', 'campaign_name' => 'K1',
            'date_start'  => '2026-06-01', 'date_stop' => '2026-06-01',
            'impressions' => '100', 'reach' => '80', 'spend' => '1.00',
            'clicks'      => '5', 'inline_link_clicks' => '4',
            'actions'     => [],
        ]], 'paging' => []], 200, [
            'x-business-use-case-usage' => json_encode(['123' => [['type' => 'ads_insights', 'call_count' => 81, 'total_cputime' => 10, 'total_time' => 12]]]),
        ]),
    ]);

    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $connection = FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);

    $job      = new SyncMetaInsightsJob($connection->uuid, 'act_123', null, 28);
    $job->job = Mockery::mock(Illuminate\Contracts\Queue\Job::class)
        ->shouldReceive('release')->once()->with(900)
        ->shouldReceive('hasFailed')->andReturn(false)
        ->shouldReceive('isReleased')->andReturn(true)
        ->getMock();

    $job->handle(app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class));

    // Der bereits geholte Batch wurde noch gespeichert, danach proaktiv re-released (Spec §12)
    expect(MetaInsightSnapshot::query()->count())->toBe(1);
});
