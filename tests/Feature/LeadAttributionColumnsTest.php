<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->phase = LeadPhase::factory()->create([LeadPhase::fkColumn('lead_board') => $this->board->getKey()]);
});

it('exposes source attribution columns on the leads table', function (): void {
    expect(Schema::hasColumn('leads', 'source_campaign_id'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'source_campaign_name'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'source_adgroup_id'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'source_adgroup_name'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'source_ad_id'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'source_ad_name'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'source_channel'))->toBeTrue();
});

it('persists source attribution via mass assignment', function (): void {
    $lead = Lead::query()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        'name'                       => 'Test Lead',
        'sort'                       => 1,
        'source_campaign_id'         => '23456789012',
        'source_campaign_name'       => 'Sommer 2026 - Bonn',
        'source_adgroup_id'          => '34567890123',
        'source_adgroup_name'        => '40-65 Jahre Bonn',
        'source_ad_id'               => '45678901234',
        'source_ad_name'             => 'KFW40 Tag',
        'source_channel'             => 'instagram',
    ]);

    $fresh = $lead->fresh();

    expect($fresh->source_campaign_id)->toBe('23456789012')
        ->and($fresh->source_campaign_name)->toBe('Sommer 2026 - Bonn')
        ->and($fresh->source_adgroup_id)->toBe('34567890123')
        ->and($fresh->source_adgroup_name)->toBe('40-65 Jahre Bonn')
        ->and($fresh->source_ad_id)->toBe('45678901234')
        ->and($fresh->source_ad_name)->toBe('KFW40 Tag')
        ->and($fresh->source_channel)->toBe('instagram');
});

it('allows leads without attribution (all nullable)', function (): void {
    $lead = Lead::query()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        'name'                       => 'Manual Lead',
        'sort'                       => 2,
    ]);

    $fresh = $lead->fresh();

    expect($fresh->source_campaign_id)->toBeNull()
        ->and($fresh->source_ad_id)->toBeNull()
        ->and($fresh->source_channel)->toBeNull();
});
