<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Drivers\ApiDriver;
use JohnWink\FilamentLeadPipeline\Drivers\MetaDriver;
use JohnWink\FilamentLeadPipeline\Drivers\ZapierDriver;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);

    $this->source = LeadSource::factory()
        ->for($this->board, 'board')
        ->active()
        ->withApiToken()
        ->create();

    $this->webhookUrl = '/' . config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks')
        . '/' . $this->source->getKey();
});

/* =======================
 * WebhookController::handle
 * ======================= */

it('creates a lead when called with a valid bearer token', function (): void {
    $payload = ['name' => 'Test Lead', 'email' => 'lead@example.com', 'phone' => '+49-0'];

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->webhookUrl, $payload);

    $response->assertCreated();

    expect(Lead::query()->where('email', 'lead@example.com')->exists())->toBeTrue();
});

it('rejects a webhook with an invalid bearer token', function (): void {
    $response = $this->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson($this->webhookUrl, ['name' => 'Hacker Lead']);

    $response->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

it('rejects a webhook without any auth header', function (): void {
    $response = $this->postJson($this->webhookUrl, ['name' => 'Anon Lead']);

    $response->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

it('returns 404 when the source is paused', function (): void {
    $this->source->update(['status' => LeadSourceStatusEnum::Paused]);

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->webhookUrl, ['name' => 'Blocked Lead']);

    $response->assertNotFound();

    expect(Lead::query()->count())->toBe(0);
});

it('returns 404 when the source id does not exist', function (): void {
    $unknownId = 'non-existent-source-id';
    $url       = '/' . config('lead-pipeline.webhooks.prefix') . '/' . $unknownId;

    $response = $this->withHeader('Authorization', 'Bearer any-token')
        ->postJson($url, []);

    $response->assertNotFound();
});

it('returns 422 when the board has no suitable open phase', function (): void {
    $this->board->phases()->delete();

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->webhookUrl, ['name' => 'Orphan Lead']);

    $response->assertStatus(422);
    expect(Lead::query()->count())->toBe(0);
});

/* =======================
 * Driver-level signature verification
 * ======================= */

it('ApiDriver: accepts matching bearer token', function (): void {
    $source = LeadSource::factory()->withApiToken()->create();

    $driver = new ApiDriver();

    expect($driver->verifySignature('payload', 'Bearer ' . $source->api_token, $source))->toBeTrue()
        ->and($driver->verifySignature('payload', $source->api_token, $source))->toBeTrue()
        ->and($driver->verifySignature('payload', 'Bearer wrong-token', $source))->toBeFalse()
        ->and($driver->verifySignature('payload', '', $source))->toBeFalse();
});

it('ZapierDriver: accepts matching bearer token', function (): void {
    $source = LeadSource::factory()->zapier()->withApiToken()->create();

    $driver = new ZapierDriver();

    expect($driver->verifySignature('payload', 'Bearer ' . $source->api_token, $source))->toBeTrue()
        ->and($driver->verifySignature('payload', 'Bearer wrong-token', $source))->toBeFalse();
});

it('MetaDriver: validates HMAC signature correctly', function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'test-secret');
    $source  = LeadSource::factory()->meta()->create();
    $driver  = new MetaDriver();
    $payload = '{"entry":[]}';
    $valid   = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret');

    expect($driver->verifySignature($payload, $valid, $source))->toBeTrue()
        ->and($driver->verifySignature($payload, 'sha256=deadbeef', $source))->toBeFalse()
        ->and($driver->verifySignature($payload, '', $source))->toBeFalse()
        ->and($driver->verifySignature('tampered-payload', $valid, $source))->toBeFalse();
});

it('MetaDriver: rejects signature when payload was modified', function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'test-secret');
    $source    = LeadSource::factory()->meta()->create();
    $driver    = new MetaDriver();
    $original  = '{"entry":[{"id":"1"}]}';
    $tampered  = '{"entry":[{"id":"2"}]}';
    $signature = 'sha256=' . hash_hmac('sha256', $original, 'test-secret');

    expect($driver->verifySignature($tampered, $signature, $source))->toBeFalse();
});

it('uses a hash_equals-safe comparison that resists length differences', function (): void {
    $source = LeadSource::factory()->withApiToken()->create(['api_token' => Str::random(64)]);
    $driver = new ApiDriver();

    // A shorter token than the stored one must never pass.
    expect($driver->verifySignature('payload', 'Bearer short', $source))->toBeFalse();
});
