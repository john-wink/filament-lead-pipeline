<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Features\SupportTesting\Testable;

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

    $this->board        = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $this->open         = LeadPhase::factory()->for($this->board, 'board')->create(['name' => 'Offen', 'sort' => 1]);
    $this->spare        = LeadPhase::factory()->for($this->board, 'board')->create(['name' => 'Termin', 'sort' => 2]);
    $this->won          = LeadPhase::factory()->for($this->board, 'board')->won()->create(['sort' => 3]);
    $this->lost         = LeadPhase::factory()->for($this->board, 'board')->lost()->create(['sort' => 4]);
    $this->disqualified = $this->board->phases()->where('type', LeadPhaseTypeEnum::Disqualified->value)->firstOrFail();
});

/**
 * @param  array<int, LeadPhase>  $phases
 */
function withoutPhaseItems(Testable $component, array $phases): Testable
{
    $state = $component->get('data.phases');

    foreach ($phases as $phase) {
        unset($state["record-{$phase->getKey()}"]);
    }

    return $component->set('data.phases', $state);
}

it('rejects removing the only phase of a terminal type with a form error instead of a server error', function (string $phase): void {
    $terminal = $this->{$phase};

    withoutPhaseItems(livewire(EditLeadBoard::class, ['record' => $this->board->getKey()]), [$terminal])
        ->call('save')
        ->assertHasFormErrors(['phases' => __('lead-pipeline::lead-pipeline.board_edit.phase_delete_last_terminal')]);

    expect(LeadPhase::query()->find($terminal->getKey()))->not->toBeNull()
        ->and($this->board->phases()->count())->toBe(5);
})->with(['won', 'lost', 'disqualified']);

it('rejects removing two phases in one save when one of them is the only phase of its terminal type', function (): void {
    withoutPhaseItems(livewire(EditLeadBoard::class, ['record' => $this->board->getKey()]), [$this->spare, $this->lost])
        ->call('save')
        ->assertHasFormErrors(['phases' => __('lead-pipeline::lead-pipeline.board_edit.phase_delete_last_terminal')]);

    expect(LeadPhase::query()->find($this->spare->getKey()))->not->toBeNull()
        ->and(LeadPhase::query()->find($this->lost->getKey()))->not->toBeNull();
});

it('rejects replacing the only won phase with a new won phase in the same save', function (): void {
    $component = withoutPhaseItems(livewire(EditLeadBoard::class, ['record' => $this->board->getKey()]), [$this->won]);

    $component
        ->set('data.phases.new-won', [
            'name'         => 'Abschluss',
            'color'        => '#10B981',
            'type'         => LeadPhaseTypeEnum::Won->value,
            'display_type' => 'list',
            'auto_convert' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['phases' => __('lead-pipeline::lead-pipeline.board_edit.phase_delete_last_terminal')]);

    expect(LeadPhase::query()->find($this->won->getKey()))->not->toBeNull()
        ->and($this->board->phases()->where('name', 'Abschluss')->exists())->toBeFalse();
});

it('rejects removing a phase that has leads and deletes nothing', function (): void {
    Lead::factory()->for($this->open, 'phase')->for($this->board, 'board')->create();

    withoutPhaseItems(livewire(EditLeadBoard::class, ['record' => $this->board->getKey()]), [$this->spare, $this->open])
        ->call('save')
        ->assertHasFormErrors(['phases' => __('lead-pipeline::lead-pipeline.board_edit.phase_delete_has_leads')]);

    expect(LeadPhase::query()->find($this->open->getKey()))->not->toBeNull()
        ->and(LeadPhase::query()->find($this->spare->getKey()))->not->toBeNull();
});

it('rejects a final state with two phases of the same terminal type', function (): void {
    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->set("data.phases.record-{$this->spare->getKey()}.type", LeadPhaseTypeEnum::Won->value)
        ->call('save')
        ->assertHasFormErrors(['phases' => __('lead-pipeline::lead-pipeline.board_edit.duplicate_terminal')]);

    expect($this->spare->fresh()->type)->toBe(LeadPhaseTypeEnum::InProgress);
});

it('rejects adding a second phase of an existing terminal type', function (): void {
    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->set('data.phases.new-lost', [
            'name'         => 'Abgesagt',
            'color'        => '#EF4444',
            'type'         => LeadPhaseTypeEnum::Lost->value,
            'display_type' => 'list',
            'auto_convert' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['phases' => __('lead-pipeline::lead-pipeline.board_edit.duplicate_terminal')]);

    expect($this->board->phases()->where('name', 'Abgesagt')->exists())->toBeFalse();
});

it('hides the delete action for phases that have leads or are the only phase of their terminal type', function (): void {
    Lead::factory()->for($this->open, 'phase')->for($this->board, 'board')->create();

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->assertFormComponentActionHidden('phases', 'delete', ['item' => "record-{$this->open->getKey()}"])
        ->assertFormComponentActionHidden('phases', 'delete', ['item' => "record-{$this->won->getKey()}"])
        ->assertFormComponentActionHidden('phases', 'delete', ['item' => "record-{$this->lost->getKey()}"])
        ->assertFormComponentActionHidden('phases', 'delete', ['item' => "record-{$this->disqualified->getKey()}"])
        ->assertFormComponentActionVisible('phases', 'delete', ['item' => "record-{$this->spare->getKey()}"]);
});

it('keeps the delete action for new items', function (): void {
    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->set('data.phases.new-phase', [
            'name'         => 'Neu',
            'color'        => '#6B7280',
            'type'         => LeadPhaseTypeEnum::InProgress->value,
            'display_type' => 'kanban',
            'auto_convert' => false,
        ])
        ->assertFormComponentActionVisible('phases', 'delete', ['item' => 'new-phase']);
});

it('still deletes an in-progress phase without leads through the delete action', function (): void {
    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->callFormComponentAction('phases', 'delete', arguments: ['item' => "record-{$this->spare->getKey()}"])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(LeadPhase::query()->find($this->spare->getKey()))->toBeNull()
        ->and($this->board->phases()->count())->toBe(4);
});
