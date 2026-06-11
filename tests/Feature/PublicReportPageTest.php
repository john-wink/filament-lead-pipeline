<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use Livewire\Livewire;

function publicReport(array $attributes = []): LeadReport
{
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    return LeadReport::factory()->create(['team_uuid' => $team->uuid, 'name' => 'Projekt Bergheim', ...$attributes]);
}

it('renders an accessible report and counts the view', function (): void {
    $report = publicReport();

    $this->get("/reports/{$report->share_token}")
        ->assertOk()
        ->assertSee('Projekt Bergheim');

    expect($report->refresh()->views_count)->toBe(1);
});

it('returns 404 for unknown, inactive, expired or soft-deleted reports', function (): void {
    $this->get('/reports/doesnotexisttokenaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();
    $this->get('/reports/' . publicReport(['is_active' => false])->share_token)->assertNotFound();
    $this->get('/reports/' . publicReport(['expires_at' => now()->subDay()])->share_token)->assertNotFound();

    $deleted = publicReport();
    $deleted->delete();
    $this->get("/reports/{$deleted->share_token}")->assertNotFound();
});

it('shows the password gate and unlocks with the correct password', function (): void {
    $report = publicReport(['password' => 'geheim']);

    $this->get("/reports/{$report->share_token}")->assertOk()->assertDontSee('Projekt Bergheim');

    Livewire::test(JohnWink\FilamentLeadPipeline\Livewire\PublicReportPage::class, ['token' => $report->share_token])
        ->set('passwordInput', 'falsch')->call('unlock')
        ->assertSet('unlocked', false)
        ->set('passwordInput', 'geheim')->call('unlock')
        ->assertSet('unlocked', true);

    // Passwort-geschützte Aufrufe zählen erst nach Unlock
    expect($report->refresh()->views_count)->toBe(1);
});

it('ignores viewer date changes when the date filter is locked', function (): void {
    $report = publicReport(['date_locked' => true, 'date_preset_default' => 'last30days']);

    Livewire::test(JohnWink\FilamentLeadPipeline\Livewire\PublicReportPage::class, ['token' => $report->share_token])
        ->call('setPreset', 'last7days')
        ->assertSet('preset', 'last30days');
});

it('rejects rotated tokens', function (): void {
    $report = publicReport();
    $old    = $report->share_token;
    $report->rotateToken();

    $this->get("/reports/{$old}")->assertNotFound();
    $this->get("/reports/{$report->refresh()->share_token}")->assertOk();
});

it('unlocks password protected reports for valid signed urls (headless pdf renderer)', function (): void {
    $report = publicReport(['password' => 'geheim']);

    $url = Illuminate\Support\Facades\URL::temporarySignedRoute(
        'lead-pipeline.reports.show',
        now()->addMinutes(5),
        ['token' => $report->share_token],
    );

    $this->get($url)
        ->assertOk()
        ->assertSee(__('lead-pipeline::reports.kpis.inquiries'));
});
