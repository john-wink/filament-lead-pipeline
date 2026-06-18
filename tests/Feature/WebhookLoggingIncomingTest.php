<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

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
        ->create(['team_uuid' => $this->team->uuid]);
    $this->url = '/' . config('lead-pipeline.webhooks.prefix') . '/' . $this->source->getKey();
});

it('logs a created incoming webhook', function (): void {
    $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->url, ['name' => 'Logged Lead', 'email' => 'logged@example.com'])
        ->assertCreated();

    $log = LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Incoming)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('created')
        ->and($log->http_status)->toBe(201)
        ->and($log->lead_source_uuid)->toBe($this->source->getKey())
        ->and($log->lead_uuid)->not->toBeNull();
});

it('logs a rejected signature', function (): void {
    $this->withHeader('Authorization', 'Bearer wrong')
        ->postJson($this->url, ['name' => 'X'])
        ->assertForbidden();

    expect(LeadWebhookLog::query()->where('outcome', 'rejected_signature')->where('http_status', 403)->exists())->toBeTrue();
});

it('logs an inactive source', function (): void {
    $this->source->update(['status' => LeadSourceStatusEnum::Paused]);

    $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->url, ['name' => 'X'])
        ->assertNotFound();

    expect(LeadWebhookLog::query()->where('outcome', 'source_inactive')->where('http_status', 404)->exists())->toBeTrue();
});

it('logs a missing phase', function (): void {
    $this->board->phases()->delete();

    $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->url, ['name' => 'X'])
        ->assertStatus(422);

    expect(LeadWebhookLog::query()->where('outcome', 'no_phase')->where('http_status', 422)->exists())->toBeTrue();
});
