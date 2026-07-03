<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Drivers\ImmoScoutDriver;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    LeadBoard::created(function (LeadBoard $board): void {
        $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    });

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->connection = ImmoScoutConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
    ]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'IS24 Board Source',
        'driver'                           => 'immoscout24',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => User::factory()->create()->getKey(),
        'config'                           => ['immoscout_connection_uuid' => $this->connection->uuid],
    ]);
});

it('shows immoscout sources created by others to board admins', function (): void {
    livewire(SourceManagement::class)
        ->assertCanSeeTableRecords([$this->source]);
});

it('queues a test import from the table action', function (): void {
    Queue::fake();

    livewire(SourceManagement::class)
        ->callTableAction('immoscout24_import_test_leads', $this->source);

    Queue::assertPushed(fn (ImportImmoScoutLeadsJob $job): bool => $job->testMode && $job->source->is($this->source));
});

it('queues a windowed import from the table action', function (): void {
    Queue::fake();

    livewire(SourceManagement::class)
        ->callTableAction('immoscout24_import_leads', $this->source, data: ['days' => 30]);

    Queue::assertPushed(fn (ImportImmoScoutLeadsJob $job): bool => 30 === $job->days && ! $job->testMode);
});

it('creates a team-scoped connection from the select create-option flow', function (): void {
    $uuid = ImmoScoutDriver::createConnection([
        'name'                => 'Neuer Zugang',
        'environment'         => ImmoScoutEnvironmentEnum::Sandbox->value,
        'consumer_key'        => 'key-1',
        'consumer_secret'     => 'secret-1',
        'access_token'        => 'token-1',
        'access_token_secret' => 'token-secret-1',
        'scout_id'            => '12345678',
    ]);

    $connection = ImmoScoutConnection::query()->findOrFail($uuid);

    expect($connection->team_uuid)->toBe($this->team->uuid)
        ->and($connection->user_uuid)->toBe($this->user->getKey())
        ->and($connection->consumer_secret)->toBe('secret-1')
        ->and($connection->environment)->toBe(ImmoScoutEnvironmentEnum::Sandbox);
});
