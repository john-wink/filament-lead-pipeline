<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\RoutingModeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardSharedTenant;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardStat;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;

it('casts routing_mode to RoutingModeEnum and routing_settings to array', function (): void {
    $board = LeadBoard::factory()->create([
        'routing_mode'     => 'fixed',
        'routing_settings' => ['region' => 'BY'],
    ]);

    $board->refresh();

    expect($board->routing_mode)->toBe(RoutingModeEnum::Fixed)
        ->and($board->routing_settings)->toBe(['region' => 'BY']);
});

it('exposes a polymorphic recipient relation', function (): void {
    $team  = Team::query()->create(['name' => 'Receiver Team', 'slug' => 'receiver-team']);
    $board = LeadBoard::factory()->create([
        'routing_mode'   => 'fixed',
        'recipient_type' => Team::class,
        'recipient_id'   => $team->getKey(),
    ]);

    expect($board->recipient())->toBeInstanceOf(MorphTo::class)
        ->and($board->recipient)->toBeInstanceOf(Team::class)
        ->and($board->recipient->getKey())->toBe($team->getKey());
});

it('has many sharedTenants entries', function (): void {
    $board = LeadBoard::factory()->create();

    LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => Team::class,
        'shared_with_id'   => (string) Str::uuid(),
        'permissions'      => ['stats'],
    ]);

    expect($board->sharedTenants())->toBeInstanceOf(HasMany::class)
        ->and($board->sharedTenants)->toHaveCount(1);
});

it('has many stats entries', function (): void {
    $board = LeadBoard::factory()->create();

    LeadBoardStat::query()->create([
        'lead_board_uuid' => $board->uuid,
        'period_date'     => '2026-04-28',
        'counts'          => ['total' => 5],
    ]);

    expect($board->stats())->toBeInstanceOf(HasMany::class)
        ->and($board->stats)->toHaveCount(1);
});

it('routingModeIs returns true only for matching enum case', function (): void {
    $board = LeadBoard::factory()->create(['routing_mode' => 'open']);

    expect($board->routingModeIs(RoutingModeEnum::Open))->toBeTrue()
        ->and($board->routingModeIs(RoutingModeEnum::Fixed))->toBeFalse()
        ->and($board->routingModeIs(RoutingModeEnum::Manual))->toBeFalse();
});

it('isSharedWith returns true when share exists', function (): void {
    $board    = LeadBoard::factory()->create();
    $tenantId = (string) Str::uuid();

    LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $board->uuid,
        'shared_with_type' => Team::class,
        'shared_with_id'   => $tenantId,
        'permissions'      => ['stats'],
    ]);

    $tenant = new Team(['uuid' => $tenantId]);
    $tenant->setRawAttributes(['uuid' => $tenantId]);
    $tenant->exists = true;

    expect($board->isSharedWith($tenant))->toBeTrue()
        ->and($board->isSharedWith($tenant, 'stats'))->toBeTrue()
        ->and($board->isSharedWith($tenant, 'leads_readonly'))->toBeFalse();
});
