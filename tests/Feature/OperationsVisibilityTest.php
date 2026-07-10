<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use JohnWink\FilamentLeadPipeline\Filament\Pages\LeadOperations;
use JohnWink\FilamentLeadPipeline\Livewire\AdvisorScorecardPanel;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::advisor-scorecard-panel', AdvisorScorecardPanel::class);
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('forces a non-admin advisor onto their own matrix row only', function (): void {
    $advisor   = User::factory()->create(['first_name' => 'Selbst', 'last_name' => 'Berater']);
    $colleague = User::factory()->create(['first_name' => 'Kollege', 'last_name' => 'Team']);
    $this->team->users()->syncWithoutDetaching([$advisor->id, $colleague->id]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]); // advisor ist KEIN Board-Admin
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $advisor->id]);
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $colleague->id]);

    $this->actingAs($advisor);
    filament()->setTenant($this->team);

    livewire(LeadOperations::class)
        ->assertSee('Selbst')
        ->assertDontSee('Kollege');
});

it('rejects a foreign advisor id on the scorecard panel for non-leadership', function (): void {
    $advisor   = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $colleague = User::factory()->create(['first_name' => 'Kol', 'last_name' => 'Lege']);
    $this->team->users()->syncWithoutDetaching([$advisor->id, $colleague->id]);
    LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->actingAs($advisor);
    filament()->setTenant($this->team);

    Livewire::test(AdvisorScorecardPanel::class, ['preset' => 'all'])
        ->dispatch('open-advisor-scorecard', advisorId: (string) $colleague->id)
        ->assertForbidden();
});

it('allows a non-leadership advisor to open their own scorecard', function (): void {
    $advisor = User::factory()->create(['first_name' => 'Eigen', 'last_name' => 'Zugriff']);
    $this->team->users()->syncWithoutDetaching([$advisor->id]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]); // advisor ist KEIN Board-Admin
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $advisor->id]);

    $this->actingAs($advisor);
    filament()->setTenant($this->team);

    Livewire::test(AdvisorScorecardPanel::class, ['preset' => 'all'])
        ->dispatch('open-advisor-scorecard', advisorId: (string) $advisor->id)
        ->assertSet('isOpen', true)
        ->assertSee('Eigen Zugriff');
});

it('rejects a forged client-side advisorId update on the scorecard panel', function (): void {
    $advisor   = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $colleague = User::factory()->create(['first_name' => 'Fremd', 'last_name' => 'Kollege']);
    $this->team->users()->syncWithoutDetaching([$advisor->id, $colleague->id]);
    LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->actingAs($advisor);
    filament()->setTenant($this->team);

    // Simuliert ein geforgtes Property-Update-Commit, das open() komplett
    // umgeht — #[Locked] muss das Client-Update hart ablehnen.
    Livewire::test(AdvisorScorecardPanel::class, ['preset' => 'all'])
        ->set('advisorId', (string) $colleague->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('self-heals a foreign advisorId that reached render without a client update', function (): void {
    $advisor   = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $colleague = User::factory()->create(['first_name' => 'Fremd', 'last_name' => 'Kollege']);
    $this->team->users()->syncWithoutDetaching([$advisor->id, $colleague->id]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]); // advisor ist KEIN Board-Admin
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $colleague->id]);

    $this->actingAs($advisor);
    filament()->setTenant($this->team);

    // Mount-Zuweisungen sind serverseitig (#[Locked] blockt nur Client-Updates)
    // — der Self-Scope in render() muss die fremde Id trotzdem auf self zurücksetzen.
    Livewire::test(AdvisorScorecardPanel::class, [
        'preset'    => 'all',
        'advisorId' => (string) $colleague->id,
        'isOpen'    => true,
    ])
        ->assertSet('advisorId', (string) $advisor->id)
        ->assertDontSee('Fremd Kollege');
});

it('keeps leadership unrestricted', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->id]); // this->user = Board-Admin = Führung

    $a     = User::factory()->create(['first_name' => 'A-Berater', 'last_name' => 'Muster']);
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $a->id]);

    livewire(LeadOperations::class)->assertSee('A-Berater');
});
