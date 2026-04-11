<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Component;

class LeadCard extends Component
{
    public string $leadId;

    public ?Lead $lead = null;

    public ?LeadPhase $phase = null;

    public function mount(string $leadId, ?string $phaseId = null): void
    {
        $this->leadId = $leadId;
        $this->lead   = Lead::with(['source', 'assignedUser', 'fieldValues.definition', 'board'])->find($leadId);

        if ($phaseId) {
            $this->phase = LeadPhase::find($phaseId);
        } elseif ($this->lead) {
            $this->phase = $this->lead->phase;
        }
    }

    public function getListPhases(): Collection
    {
        if ( ! $this->lead) {
            return collect();
        }

        return $this->lead->board->phases()
            ->list()
            ->ordered()
            ->get();
    }

    public function moveToListPhase(string $phaseId, ?string $reason = null): void
    {
        if ( ! $this->lead) {
            return;
        }

        $targetPhase = LeadPhase::find($phaseId);
        if ( ! $targetPhase) {
            return;
        }

        $fromPhase = $this->lead->phase;
        $this->lead->moveToPhase($targetPhase);

        // Status synchronisieren
        if (LeadPhaseTypeEnum::Won === $targetPhase->type) {
            $this->lead->update(['status' => LeadStatusEnum::Won]);
        } elseif (LeadPhaseTypeEnum::Lost === $targetPhase->type) {
            $this->lead->update([
                'status'      => LeadStatusEnum::Lost,
                'lost_at'     => now(),
                'lost_reason' => $reason,
            ]);
        }

        $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
        $this->dispatch('phase-updated', phaseId: $targetPhase->getKey());
    }

    public function assignUser(string $userId): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->lead->update([
            'assigned_to' => filled($userId) ? $userId : null,
        ]);

        $assigneeName = filled($userId)
            ? config('lead-pipeline.user_model')::find($userId)?->name ?? __('lead-pipeline::lead-pipeline.field.unknown')
            : null;

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Assignment->value,
            'description' => $assigneeName
                ? __('lead-pipeline::lead-pipeline.actions.assigned_to_name', ['name' => $assigneeName])
                : __('lead-pipeline::lead-pipeline.actions.assignment_removed'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $this->lead->load(['assignedUser']);

        // Auto-move: if lead is in Open phase and gets assigned, move to first InProgress phase
        if (filled($userId) && $this->lead->phase) {
            $currentPhase = $this->lead->phase;
            if (LeadPhaseTypeEnum::Open === $currentPhase->type) {
                $firstInProgress = $this->lead->board->phases()
                    ->where('type', LeadPhaseTypeEnum::InProgress)
                    ->ordered()
                    ->first();

                if ($firstInProgress) {
                    $fromPhase = $this->lead->phase;
                    $this->lead->moveToPhase($firstInProgress);
                    $this->dispatch('phase-updated', phaseId: $fromPhase->getKey());
                    $this->dispatch('phase-updated', phaseId: $firstInProgress->getKey());
                }
            }
        }

        $this->dispatch('phase-updated', phaseId: $this->phase?->getKey() ?? '');
    }

    public function render(): View
    {
        return view('lead-pipeline::kanban.lead-card');
    }
}
