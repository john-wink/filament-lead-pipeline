<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages\CreateLeadReport;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages\ListLeadReports;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    // Report-Permissions für den Fixture-User freischalten — die Policy-Mechanik
    // selbst ist in LeadReportPolicyTest abgedeckt.
    Gate::before(fn ($user, string $ability): ?bool => in_array($ability, [
        'view_reports', 'create_reports', 'update_reports', 'delete_reports', 'manage_sharing',
    ], true) ? true : null);
});

it('lists only reports of the current team', function (): void {
    $foreign = Team::factory()->create();

    $own   = LeadReport::factory()->create(['team_uuid' => $this->team->uuid]);
    $other = LeadReport::factory()->create(['team_uuid' => $foreign->uuid]);

    livewire(ListLeadReports::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other]);
});

it('creates a report with board and ad source', function (): void {
    $board      = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $connection = FacebookConnection::factory()->create(['team_uuid' => $this->team->uuid, 'scopes' => ['ads_read'], 'user_uuid' => $this->user->id]);

    livewire(CreateLeadReport::class)
        ->fillForm([
            'name'                => 'Bergheim Report',
            'date_preset_default' => 'last30days',
            'boards'              => [$board->uuid],
            'adSources'           => [[
                'facebook_connection_uuid' => $connection->uuid,
                'ad_account_id'            => 'act_123',
                'campaign_ids'             => null,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $report = LeadReport::query()->where('name', 'Bergheim Report')->first();
    expect($report)->not->toBeNull()
        ->and($report->boards)->toHaveCount(1)
        ->and($report->adSources)->toHaveCount(1)
        ->and($report->team_uuid)->toBe($this->team->uuid);
});

it('rejects connections of foreign teams', function (): void {
    $foreign           = Team::factory()->create();
    $foreignConnection = FacebookConnection::factory()->create(['team_uuid' => $foreign->uuid, 'user_uuid' => $this->user->id]);

    livewire(CreateLeadReport::class)
        ->fillForm([
            'name'      => 'Boese',
            'adSources' => [[
                'facebook_connection_uuid' => $foreignConnection->uuid,
                'ad_account_id'            => 'act_666',
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors();
});

it('rejects boards of foreign teams', function (): void {
    $foreign      = Team::factory()->create();
    $foreignBoard = LeadBoard::factory()->create(['team_uuid' => $foreign->uuid]);

    livewire(CreateLeadReport::class)
        ->fillForm(['name' => 'Boese Boards', 'boards' => [$foreignBoard->uuid]])
        ->call('create')
        ->assertHasFormErrors();
});

it('accepts boards shared with the current team via LeadBoardSharedTenant', function (): void {
    $foreign     = Team::factory()->create();
    $sharedBoard = LeadBoard::factory()->create(['team_uuid' => $foreign->uuid]);

    JohnWink\FilamentLeadPipeline\Models\LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $sharedBoard->uuid,
        'shared_with_type' => $this->team::class,
        'shared_with_id'   => (string) $this->team->uuid,
    ]);

    livewire(CreateLeadReport::class)
        ->fillForm(['name' => 'Geteilt', 'boards' => [$sharedBoard->uuid]])
        ->call('create')
        ->assertHasNoFormErrors();
});
