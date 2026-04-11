<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\Contracts\View\View;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Events\LeadMoved;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\On;
use Livewire\Component;

class KanbanBoard extends Component
{
    public LeadBoard $board;

    public string $newLeadName = '';

    public string $newLeadEmail = '';

    public string $newLeadPhone = '';

    public ?string $createInPhaseId = null;

    public bool $showCreateModal = false;

    public function mount(LeadBoard $board): void
    {
        $this->board = $board;
    }

    #[On('create-lead')]
    #[On('open-create-modal')]
    public function openCreateModal(?string $phaseId = null): void
    {
        $this->createInPhaseId = $phaseId;
        $this->showCreateModal = true;
        $this->reset(['newLeadName', 'newLeadEmail', 'newLeadPhone']);
    }

    public function createLead(): void
    {
        $this->validate([
            'newLeadName'  => 'required|string|max:255',
            'newLeadEmail' => 'nullable|email|max:255',
            'newLeadPhone' => 'nullable|string|max:50',
        ]);

        $boardFk = LeadBoard::fkColumn('lead_board');
        $phaseFk = LeadPhase::fkColumn('lead_phase');

        $phaseId = $this->createInPhaseId
            ?? $this->board->phases()->kanban()->ordered()->first()?->getKey();

        $lead = Lead::query()->create([
            $boardFk => $this->board->getKey(),
            $phaseFk => $phaseId,
            'name'   => $this->newLeadName,
            'email'  => $this->newLeadEmail ?: null,
            'phone'  => $this->newLeadPhone ?: null,
            'status' => LeadStatusEnum::Active,
            'sort'   => 0,
        ]);

        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Created->value,
            'description' => 'Lead created manually',
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        LeadCreated::dispatch($lead);

        $this->showCreateModal = false;
        $this->dispatch('phase-updated', phaseId: $phaseId);
    }

    public function moveLeadToPhase(string $leadId, string $phaseId, int $newSort): void
    {
        // Check board ownership
        if (config('lead-pipeline.tenancy.enabled')) {
            $teamFk   = config('lead-pipeline.tenancy.foreign_key');
            $tenantId = filament()->getTenant()?->getKey();

            if ($this->board->{$teamFk} !== $tenantId) {
                abort(403, 'Nicht autorisiert.');
            }
        }

        $lead     = Lead::findOrFail($leadId);
        $oldPhase = $lead->phase;
        $newPhase = LeadPhase::findOrFail($phaseId);

        // Verify lead and phase belong to this board
        if ($lead->{Lead::fkColumn('lead_board')} !== $this->board->getKey()) {
            abort(403, 'Lead does not belong to this board.');
        }
        if ($newPhase->{LeadPhase::fkColumn('lead_board')} !== $this->board->getKey()) {
            abort(403, 'Phase does not belong to this board.');
        }

        $lead->update([
            Lead::fkColumn('lead_phase') => $newPhase->getKey(),
            'sort'                       => $newSort,
        ]);

        if ( ! $oldPhase || $oldPhase->getKey() !== $newPhase->getKey()) {
            $lead->activities()->create([
                'type'        => LeadActivityTypeEnum::Moved->value,
                'description' => sprintf(
                    __('lead-pipeline::lead-pipeline.activity.moved_from_to'),
                    $oldPhase?->name ?? __('lead-pipeline::lead-pipeline.activity.no_phase'),
                    $newPhase->name,
                ),
                'properties' => [
                    'old_phase' => $oldPhase?->getKey(),
                    'new_phase' => $newPhase->getKey(),
                ],
            ]);

        }

        if ($oldPhase) {
            $this->dispatch('phase-updated', phaseId: $oldPhase->getKey());
        }
        $this->dispatch('phase-updated', phaseId: $newPhase->getKey());
    }

    public function reorderLeads(string $phaseId, array $orderedIds): void
    {
        $boardKey = $this->board->getKey();

        foreach ($orderedIds as $index => $leadId) {
            Lead::where(Lead::pkColumn(), $leadId)
                ->where(Lead::fkColumn('lead_board'), $boardKey)
                ->update(['sort' => $index]);
        }

        $this->dispatch('phase-updated', phaseId: $phaseId);
    }

    public function render(): View
    {
        $phases = $this->board
            ?->phases()
            ->kanban()
            ->ordered()
            ->get() ?? collect();

        return view('lead-pipeline::kanban.board', [
            'phases' => $phases,
        ]);
    }
}
