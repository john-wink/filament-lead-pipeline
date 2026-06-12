<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->actingAs($this->user);

    $board      = LeadBoard::factory()->create();
    $phase      = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $this->lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Maria Weber',
    ]);
});

it('records each call result as a call activity', function (string $result): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('recordCallResult', $result);

    $activity = $this->lead->activities()->where('type', LeadActivityTypeEnum::Call->value)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->properties['call_result'])->toBe($result)
        ->and($activity->description)->toBe(__('lead-pipeline::lead-pipeline.activity.call_result_' . $result));
})->with(['reached', 'voicemail', 'not_reached', 'callback']);

it('ignores unknown call results', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('recordCallResult', 'carrier_pigeon');

    expect($this->lead->activities()->where('type', LeadActivityTypeEnum::Call->value)->count())->toBe(0);
});

it('shows the freshly recorded call in the activity timeline', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('recordCallResult', 'voicemail')
        ->assertSee(__('lead-pipeline::lead-pipeline.activity.call_result_voicemail'));
});

it('renders the quick menu buttons in the modal', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSeeHtml("recordCallResult('reached')")
        ->assertSeeHtml("recordCallResult('voicemail')")
        ->assertSeeHtml("recordCallResult('not_reached')")
        ->assertSeeHtml("recordCallResult('callback')");
});
