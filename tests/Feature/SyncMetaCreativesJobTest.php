<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaCreativesJob;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\MetaAdCreative;

it('downloads creative images to the configured disk and upserts creatives', function (): void {
    config()->set('lead-pipeline.reports.media_disk', 'reports-test');
    Storage::fake('reports-test');

    Http::fake([
        'graph.facebook.com/*/act_123/ads*' => Http::response(['data' => [[
            'id'       => 'ad_1', 'name' => 'Anzeige 1', 'status' => 'ACTIVE', 'campaign_id' => 'c1',
            'creative' => ['image_url' => 'https://cdn.fb/full.jpg', 'thumbnail_url' => 'https://cdn.fb/thumb.jpg', 'image_hash' => 'hash1'],
            'insights' => ['data' => [['impressions' => '12345', 'spend' => '99.50', 'actions' => [['action_type' => 'lead', 'value' => '7']]]]],
        ]]]),
        'graph.facebook.com/*/act_123/adimages*' => Http::response(['data' => [['hash' => 'hash1', 'permanent_url' => 'https://perm.fb/full.jpg']]]),
        'cdn.fb/*'                               => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
        'perm.fb/*'                              => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $connection = FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);

    (new SyncMetaCreativesJob($connection->uuid, 'act_123'))->handle(
        app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class),
    );

    $creative = MetaAdCreative::query()->where('ad_id', 'ad_1')->first();
    expect($creative)->not->toBeNull()
        ->and($creative->image_path)->toBe('lead-reports/creatives/act_123/ad_1.jpg')
        ->and((int) $creative->lifetime_impressions)->toBe(12345)
        ->and((int) $creative->lifetime_leads)->toBe(7)
        ->and((float) $creative->lifetime_spend)->toBe(99.5);
    Storage::disk('reports-test')->assertExists('lead-reports/creatives/act_123/ad_1.jpg');

    // permanent_url wird bevorzugt heruntergeladen (Spec §5)
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://perm.fb/'));
});

it('keeps the cached image when meta returns no image url', function (): void {
    config()->set('lead-pipeline.reports.media_disk', 'reports-test');
    Storage::fake('reports-test');

    Http::fake(['graph.facebook.com/*/act_123/ads*' => Http::response(['data' => [[
        'id' => 'ad_1', 'name' => 'Anzeige 1', 'status' => 'ACTIVE', 'campaign_id' => 'c1', 'creative' => [],
    ]]])]);

    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $connection = FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'access_token' => 'tok', 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    MetaAdCreative::factory()->create(['team_uuid' => $team->uuid, 'ad_id' => 'ad_1', 'image_path' => 'lead-reports/creatives/act_123/ad_1.jpg']);

    (new SyncMetaCreativesJob($connection->uuid, 'act_123'))->handle(
        app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class),
    );

    expect(MetaAdCreative::query()->where('ad_id', 'ad_1')->first()->image_path)
        ->toBe('lead-reports/creatives/act_123/ad_1.jpg');
});
