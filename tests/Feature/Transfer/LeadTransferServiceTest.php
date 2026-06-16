<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadTransferred;
use JohnWink\FilamentLeadPipeline\Exceptions\LeadAlreadyTransferredException;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Services\LeadTransferService;

function makeTransferLead(string $teamKey): array
{
    $origin   = LeadBoard::factory()->withDefaultPhases()->create(['team_uuid' => $teamKey]);
    $target   = LeadBoard::factory()->withDefaultPhases()->create(['team_uuid' => $teamKey]);
    $wonPhase = $origin->phases()->where('type', LeadPhaseTypeEnum::Won)->first();

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $origin->getKey(),
        Lead::fkColumn('lead_phase') => $wonPhase->getKey(),
        'name'                       => 'Anna Müller',
        'email'                      => 'anna@example.test',
        'phone'                      => '+49 123',
    ]);

    return [$origin, $target, $lead];
}

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('reports transfer disabled by default', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);

    expect($board->transferEnabled())->toBeFalse();
});

it('reports transfer enabled when board setting is on', function (): void {
    $board = LeadBoard::factory()->create([
        'team_uuid' => $this->team->getKey(),
        'settings'  => ['transfer_enabled' => true],
    ]);

    expect($board->transferEnabled())->toBeTrue();
});

it('reports transfer disabled when global kill switch is off', function (): void {
    config()->set('lead-pipeline.transfer.enabled', false);
    $board = LeadBoard::factory()->create([
        'team_uuid' => $this->team->getKey(),
        'settings'  => ['transfer_enabled' => true],
    ]);

    expect($board->transferEnabled())->toBeFalse();
});

it('links a transferred lead back to its origin and forward to copies', function (): void {
    $originBoard = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $targetBoard = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);

    $origin = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $originBoard->getKey(),
    ]);

    $transferSource = $targetBoard->sources()->create([
        'name'      => 'Übergabe',
        'driver'    => LeadSourceTypeEnum::InternalTransfer->value,
        'status'    => LeadSourceStatusEnum::Active,
        'config'    => ['origin_board' => $originBoard->getKey()],
        'team_uuid' => $this->team->getKey(),
    ]);

    $copy = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $targetBoard->getKey(),
        Lead::fkColumn('lead_source') => $transferSource->getKey(),
        'external_id'                 => $origin->getKey(),
    ]);

    expect($copy->originLead()?->getKey())->toBe($origin->getKey())
        ->and($copy->isTransferred())->toBeTrue()
        ->and($origin->transferredLeads()->pluck(Lead::pkColumn())->all())->toContain($copy->getKey());
});

it('creates a linked lead in the target board carrying identity', function (): void {
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());

    $new = app(LeadTransferService::class)->transfer($lead, $target, null, null, 'Bitte zügig anrufen.');

    expect($new->{Lead::fkColumn('lead_board')})->toBe($target->getKey())
        ->and($new->external_id)->toBe($lead->getKey())
        ->and($new->name)->toBe('Anna Müller')
        ->and($new->source->driver)->toBe(LeadSourceTypeEnum::InternalTransfer->value)
        ->and($new->phase->type->isTerminal())->toBeFalse();
});

it('is idempotent — second transfer to same board throws', function (): void {
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());
    $service                  = app(LeadTransferService::class);
    $service->transfer($lead, $target, null, null, 'note');

    $service->transfer($lead, $target, null, null, 'note');
})->throws(LeadAlreadyTransferredException::class);

it('refuses transfer to the same board', function (): void {
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());

    app(LeadTransferService::class)->transfer($lead, $origin, null, null, 'note');
})->throws(InvalidArgumentException::class);

it('reuses one transfer source per origin board', function (): void {
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());
    $lead2                    = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $origin->getKey(),
        Lead::fkColumn('lead_phase') => $origin->phases()->where('type', LeadPhaseTypeEnum::Won)->first()->getKey(),
    ]);
    $service = app(LeadTransferService::class);

    $service->transfer($lead, $target, null, null, 'a');
    $service->transfer($lead2, $target, null, null, 'b');

    expect($target->sources()->where('driver', LeadSourceTypeEnum::InternalTransfer->value)->count())->toBe(1);
});

it('maps custom field values by key and skips non-matching', function (): void {
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());
    $originDef                = LeadFieldDefinition::factory()->for($origin, 'board')->create(['key' => 'budget']);
    LeadFieldDefinition::factory()->for($target, 'board')->create(['key' => 'budget']);
    $onlyOrigin = LeadFieldDefinition::factory()->for($origin, 'board')->create(['key' => 'only_origin']);
    $lead->setFieldValue($originDef, '50000');
    $lead->setFieldValue($onlyOrigin, 'x');

    $new = app(LeadTransferService::class)->transfer($lead, $target, null, null, 'note');

    expect($new->getFieldValue('budget'))->toBe('50000')
        ->and($new->getFieldValue('only_origin'))->toBeNull();
});

it('writes a Transferred activity on both leads and fires the event', function (): void {
    Event::fake([LeadTransferred::class]);
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());

    $new = app(LeadTransferService::class)->transfer($lead, $target, null, null, 'worauf achten');

    expect($new->activities()->where('type', LeadActivityTypeEnum::Transferred->value)->count())->toBe(1)
        ->and($lead->activities()->where('type', LeadActivityTypeEnum::Transferred->value)->count())->toBe(1);
    Event::assertDispatched(LeadTransferred::class);
});

it('assigns the given onboarding employee', function (): void {
    [$origin, $target, $lead] = makeTransferLead($this->team->getKey());

    $new = app(LeadTransferService::class)->transfer($lead, $target, null, (string) $this->user->getKey(), 'note');

    expect((string) $new->assigned_to)->toBe((string) $this->user->getKey());
});
