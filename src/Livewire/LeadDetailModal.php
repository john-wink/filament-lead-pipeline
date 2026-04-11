<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\On;
use Livewire\Component;

class LeadDetailModal extends Component
{
    public ?string $leadId = null;

    public ?Lead $lead = null;

    public bool $isOpen = false;

    public string $newNote = '';

    #[On('open-lead-detail')]
    public function openModal(string $leadId): void
    {
        $this->leadId = $leadId;
        $this->lead   = Lead::with([
            'source',
            'assignedUser',
            'phase',
            'board',
            'fieldValues.definition',
            'activities' => fn ($q) => $q->latest()->limit(50),
            'activities.causer',
        ])->findOrFail($leadId);
        $this->isOpen  = true;
        $this->newNote = '';
    }

    public function closeModal(): void
    {
        $this->reset(['leadId', 'lead', 'isOpen', 'newNote']);
    }

    public function addNote(): void
    {
        $this->authorizeAccess();

        if ('' === mb_trim($this->newNote)) {
            return;
        }

        $this->lead?->activities()->create([
            'type'        => LeadActivityTypeEnum::Note->value,
            'description' => $this->newNote,
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $this->newNote = '';
        $this->lead?->load(['activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer', 'fieldValues.definition']);
    }

    public function markAsLost(string $reason = ''): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $this->lead->update([
            'status'      => LeadStatusEnum::Lost,
            'lost_at'     => now(),
            'lost_reason' => $reason,
        ]);

        $originalStatus = $this->lead->getOriginal('status');
        $oldStatus      = $originalStatus instanceof LeadStatusEnum
            ? $originalStatus
            : LeadStatusEnum::tryFrom((string) ($originalStatus ?? '')) ?? LeadStatusEnum::Active;
        \JohnWink\FilamentLeadPipeline\Events\LeadStatusChanged::dispatch(
            $this->lead,
            $oldStatus,
            LeadStatusEnum::Lost,
        );

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Updated->value,
            'description' => '' !== $reason
                ? __('lead-pipeline::lead-pipeline.actions.lost_with_reason', ['reason' => $reason])
                : __('lead-pipeline::lead-pipeline.actions.lost_no_reason'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $lostPhase = $this->lead->board->phases()
            ->where('type', LeadPhaseTypeEnum::Lost)
            ->first();

        if ($lostPhase) {
            $fromPhase = $this->lead->phase;
            $this->lead->moveToPhase($lostPhase);
            $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
            $this->dispatch('phase-updated', phaseId: $lostPhase->getKey());
        }

        $this->lead->refresh()->load(['source', 'assignedUser', 'phase', 'board', 'fieldValues.definition', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer']);
        $this->dispatch('phase-updated', phaseId: $this->lead->phase?->getKey() ?? '');
    }

    public function assignUser(string $userId): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

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

        $this->lead->load(['assignedUser', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer', 'fieldValues.definition']);

        \JohnWink\FilamentLeadPipeline\Events\LeadAssigned::dispatch(
            $this->lead,
            filled($userId) ? config('lead-pipeline.user_model')::find($userId) : null,
            auth()->user(),
        );

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

        $this->dispatch('phase-updated', phaseId: $this->lead->phase?->getKey() ?? '');
    }

    public function updateField(string $field, mixed $value): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $rules = match ($field) {
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'value' => ['nullable', 'numeric', 'min:0'],
            default => [],
        };

        if (empty($rules)) {
            return;
        }

        $validator = Validator::make(
            [$field => $value],
            [$field => $rules],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $this->lead->update([$field => $value]);
        $this->lead->refresh()->load(['source', 'assignedUser', 'phase', 'board', 'fieldValues.definition', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer']);
        $this->dispatch('phase-updated', phaseId: $this->lead->phase?->getKey() ?? '');
    }

    public function updateCustomField(string $definitionId, mixed $value): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $definition = LeadFieldDefinition::findOrFail($definitionId);

        $castValue = is_array($value) ? $value : (string) $value;
        $this->lead->setFieldValue($definition, $castValue);
        $this->lead->load(['fieldValues.definition']);
    }

    public function markAsWon(): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $originalStatus = $this->lead->getOriginal('status');
        $oldStatus      = $originalStatus instanceof LeadStatusEnum
            ? $originalStatus
            : LeadStatusEnum::tryFrom((string) ($originalStatus ?? '')) ?? LeadStatusEnum::Active;

        $this->lead->update(['status' => LeadStatusEnum::Won]);

        \JohnWink\FilamentLeadPipeline\Events\LeadStatusChanged::dispatch(
            $this->lead,
            $oldStatus,
            LeadStatusEnum::Won,
        );

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Updated->value,
            'description' => __('lead-pipeline::lead-pipeline.activity.marked_won'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $wonPhase = $this->lead->board->phases()
            ->where('type', LeadPhaseTypeEnum::Won)
            ->first();

        if ($wonPhase) {
            $fromPhase = $this->lead->phase;
            $this->lead->moveToPhase($wonPhase);
            $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
            $this->dispatch('phase-updated', phaseId: $wonPhase->getKey());
        }

        $this->lead->refresh()->load(['source', 'assignedUser', 'phase', 'board', 'fieldValues.definition', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer']);
        $this->lead->load(['phase', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer', 'fieldValues.definition']);
    }

    public function changePhase(string $phaseId): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $newPhase = LeadPhase::findOrFail($phaseId);

        // Verify phase belongs to the same board
        if ($newPhase->{LeadPhase::fkColumn('lead_board')} !== $this->lead->{Lead::fkColumn('lead_board')}) {
            abort(403, 'Phase does not belong to this board.');
        }

        $fromPhase = $this->lead->phase;
        $this->lead->moveToPhase($newPhase);

        $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
        $this->dispatch('phase-updated', phaseId: $newPhase->getKey());

        $this->lead->refresh()->load(['source', 'assignedUser', 'phase', 'board', 'fieldValues.definition', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer']);
        $this->lead->load(['phase', 'activities' => fn ($q) => $q->latest()->limit(50), 'activities.causer', 'fieldValues.definition']);
    }

    public function render(): View
    {
        return view('lead-pipeline::kanban.lead-detail-modal');
    }

    private function authorizeAccess(): void
    {
        if ( ! config('lead-pipeline.tenancy.enabled')) {
            return;
        }

        $teamFk   = config('lead-pipeline.tenancy.foreign_key');
        $tenantId = filament()->getTenant()?->getKey();

        if ($this->lead->board->{$teamFk} !== $tenantId) {
            abort(403, 'Nicht autorisiert.');
        }
    }
}
