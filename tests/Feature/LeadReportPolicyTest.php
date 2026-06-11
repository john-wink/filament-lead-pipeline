<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

function reportPolicyUser(): App\Models\User
{
    return App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
}

it('denies report actions without the configured permission', function (): void {
    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid]);

    expect(Gate::forUser(reportPolicyUser())->allows('view', $report))->toBeFalse()
        ->and(Gate::forUser(reportPolicyUser())->allows('create', LeadReport::class))->toBeFalse();
});

it('allows actions when the user has the permission (host check via can)', function (): void {
    config()->set('lead-pipeline.reports.permissions.view', 'view_reports');

    $user = Mockery::mock(App\Models\User::class)->makePartial();
    $user->shouldReceive('can')->with('view_reports')->andReturnTrue();

    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid]);

    $this->actingAs(reportPolicyUser());
    Filament\Facades\Filament::setTenant($team);

    expect(app(JohnWink\FilamentLeadPipeline\Policies\LeadReportPolicy::class)->view($user, $report))->toBeTrue();
});

it('denies access to reports of foreign teams even with permission', function (): void {
    $user = Mockery::mock(App\Models\User::class)->makePartial();
    $user->shouldReceive('can')->andReturnTrue();

    $foreign = App\Models\Team::factory()->create();
    $report  = LeadReport::factory()->create(['team_uuid' => $foreign->uuid]);

    expect(app(JohnWink\FilamentLeadPipeline\Policies\LeadReportPolicy::class)->view($user, $report))->toBeFalse();
});
