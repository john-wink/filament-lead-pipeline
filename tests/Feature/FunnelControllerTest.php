<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    $this->team   = Team::query()->firstWhere('slug', 'test');
    $this->board  = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->phase  = LeadPhase::factory()->for($this->board, 'board')->create();
    $this->source = LeadSource::factory()->for($this->board, 'board')->funnel()->create();
});

it('renders an active funnel and increments the view counter', function (): void {
    $funnel = LeadFunnel::factory()
        ->for($this->board, 'board')
        ->for($this->source, 'source')
        ->create([
            'slug'        => 'fn-active',
            'is_active'   => true,
            'views_count' => 5,
        ]);

    $this->get('/funnel/fn-active')->assertOk();

    expect($funnel->fresh()->views_count)->toBe(6);
});

it('returns 404 when the funnel slug is unknown', function (): void {
    $this->get('/funnel/missing')->assertNotFound();
});

it('returns 404 when the funnel exists but is inactive', function (): void {
    LeadFunnel::factory()
        ->for($this->board, 'board')
        ->for($this->source, 'source')
        ->create(['slug' => 'fn-inactive', 'is_active' => false]);

    $this->get('/funnel/fn-inactive')->assertNotFound();
});
