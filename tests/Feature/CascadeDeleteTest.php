<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldValue;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->create();
    $this->lead  = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();
});

it('cascade-deletes lead_field_values when the lead is force-deleted', function (): void {
    $definition = LeadFieldDefinition::factory()->for($this->board, 'board')->create(['key' => 'company']);

    LeadFieldValue::query()->create([
        'lead_uuid'                   => $this->lead->uuid,
        'lead_field_definition_uuid'  => $definition->uuid,
        'value'                       => 'Acme',
    ]);

    expect(LeadFieldValue::query()->where('lead_uuid', $this->lead->uuid)->count())->toBe(1);

    $this->lead->forceDelete();

    expect(LeadFieldValue::query()->where('lead_uuid', $this->lead->uuid)->count())->toBe(0);
});

it('cascade-deletes lead_field_values when the field definition is force-deleted', function (): void {
    $definition = LeadFieldDefinition::factory()->for($this->board, 'board')->create(['key' => 'budget']);

    LeadFieldValue::query()->create([
        'lead_uuid'                   => $this->lead->uuid,
        'lead_field_definition_uuid'  => $definition->uuid,
        'value'                       => '100k',
    ]);

    $definition->forceDelete();

    expect(LeadFieldValue::query()->where('lead_field_definition_uuid', $definition->uuid)->count())->toBe(0);
});

it('cascade-deletes phases and their leads when the board is force-deleted', function (): void {
    $leadId  = $this->lead->uuid;
    $phaseId = $this->phase->uuid;

    $this->board->forceDelete();

    expect(LeadPhase::query()->where('uuid', $phaseId)->exists())->toBeFalse()
        ->and(Lead::query()->where('uuid', $leadId)->exists())->toBeFalse();
});

it('keeps lead activities in sync (cascade on lead delete)', function (): void {
    $this->lead->activities()->create([
        'type'        => 'created',
        'description' => 'test',
    ]);

    expect(LeadActivity::query()->where('lead_uuid', $this->lead->uuid)->count())->toBeGreaterThanOrEqual(1);

    $this->lead->forceDelete();

    expect(LeadActivity::query()->where('lead_uuid', $this->lead->uuid)->count())->toBe(0);
});
