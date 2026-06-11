<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource\Pages\EditLeadReport;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaInsightsJob;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    Gate::before(fn ($user, string $ability): ?bool => in_array($ability, [
        'view_reports', 'create_reports', 'update_reports', 'delete_reports', 'manage_sharing',
    ], true) ? true : null);
});

function reportWithSource(): LeadReport
{
    $team       = Team::query()->firstWhere('slug', 'test');
    $report     = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $connection = FacebookConnection::factory()->create([
        'team_uuid' => $team->uuid,
        'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
    ]);
    $report->adSources()->create(['facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_77']);

    return $report;
}

it('dispatches a sync when refresh action is called and throttles repeats', function (): void {
    Queue::fake();
    $report = reportWithSource();

    livewire(EditLeadReport::class, ['record' => $report->uuid])->callAction('refresh');
    Queue::assertPushed(SyncMetaInsightsJob::class, 1);

    livewire(EditLeadReport::class, ['record' => $report->uuid])->callAction('refresh');
    Queue::assertPushed(SyncMetaInsightsJob::class, 1); // gedrosselt, kein zweiter Dispatch
});

it('rotates the share token via action', function (): void {
    $report = reportWithSource();
    $old    = $report->share_token;

    livewire(EditLeadReport::class, ['record' => $report->uuid])->callAction('rotateToken');

    expect($report->refresh()->share_token)->not->toBe($old);
});
