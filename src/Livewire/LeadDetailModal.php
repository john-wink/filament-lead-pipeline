<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class LeadDetailModal extends Component
{
    private const int ACTIVITIES_PER_PAGE = 10;

    public ?string $leadId = null;

    public bool $isOpen = false;

    public string $newNote = '';

    public int $activitiesLimit = self::ACTIVITIES_PER_PAGE;

    /** Request-Cache der Board-Phasen — das Blade fragt sie mehrfach ab. */
    private ?Collection $boardPhasesCache = null;

    #[On('open-lead-detail')]
    public function openModal(string $leadId): void
    {
        Lead::query()->whereKey($leadId)->firstOrFail();

        $this->leadId          = $leadId;
        $this->activitiesLimit = self::ACTIVITIES_PER_PAGE;
        $this->isOpen          = true;
        $this->newNote         = '';
        unset($this->lead);
    }

    /**
     * Livewire serialisiert nur die leadId — das Model samt Relationen wird pro
     * Request GENAU EINMAL geladen (Computed-Cache) statt bei jeder Hydration.
     */
    #[Computed]
    public function lead(): ?Lead
    {
        if (null === $this->leadId) {
            return null;
        }

        return Lead::with([
            'source',
            'assignedUser',
            'phase',
            'board',
            'fieldValues.definition',
            ...$this->activityRelations(),
        ])->find($this->leadId);
    }

    public function closeModal(): void
    {
        $this->reset(['leadId', 'isOpen', 'newNote', 'activitiesLimit']);
        unset($this->lead);
    }

    public function loadMoreActivities(): void
    {
        $this->activitiesLimit += self::ACTIVITIES_PER_PAGE;
        $this->reloadActivities();
    }

    public function hasMoreActivities(): bool
    {
        if (null === $this->lead) {
            return false;
        }

        return $this->lead->activities()->count() > $this->lead->activities->count();
    }

    /** @return Collection<int, LeadPhase> */
    public function boardPhases(): Collection
    {
        if (null === $this->lead) {
            return collect();
        }

        return $this->boardPhasesCache ??= $this->lead->board->phases()->ordered()->get();
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
        $this->reloadActivities();
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

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Updated->value,
            'description' => '' !== $reason
                ? __('lead-pipeline::lead-pipeline.actions.lost_with_reason', ['reason' => $reason])
                : __('lead-pipeline::lead-pipeline.actions.lost_no_reason'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $lostPhase = $this->boardPhases()->firstWhere('type', LeadPhaseTypeEnum::Lost);

        if ($lostPhase) {
            $fromPhase = $this->lead->phase;
            $this->lead->moveToPhase($lostPhase);
            $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
            $this->dispatch('phase-updated', phaseId: $lostPhase->getKey());
        }

        $this->lead->load('phase');
        $this->reloadActivities();
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

        $this->lead->load('assignedUser');
        $this->reloadActivities();

        // Auto-move: if lead is in Open phase and gets assigned, move to first InProgress phase
        if (filled($userId) && $this->lead->phase) {
            $currentPhase = $this->lead->phase;
            if (LeadPhaseTypeEnum::Open === $currentPhase->type) {
                $firstInProgress = $this->boardPhases()
                    ->where('type', LeadPhaseTypeEnum::InProgress)
                    ->sortBy('sort')
                    ->first();

                if ($firstInProgress) {
                    $fromPhase = $this->lead->phase;
                    $this->lead->moveToPhase($firstInProgress);
                    $this->lead->load('phase');
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

        // Selektives Update: update() mutiert das geladene Model — kein Reload des Relation-Graphen nötig
        $this->lead->update([$field => $value]);
        $this->dispatch('phase-updated', phaseId: $this->lead->{Lead::fkColumn('lead_phase')} ?? '');
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

        $this->lead->update(['status' => LeadStatusEnum::Won]);

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Updated->value,
            'description' => __('lead-pipeline::lead-pipeline.activity.marked_won'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $wonPhase = $this->boardPhases()->firstWhere('type', LeadPhaseTypeEnum::Won);

        if ($wonPhase) {
            $fromPhase = $this->lead->phase;
            $this->lead->moveToPhase($wonPhase);
            $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
            $this->dispatch('phase-updated', phaseId: $wonPhase->getKey());
        }

        $this->lead->load('phase');
        $this->reloadActivities();
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

        $this->lead->load('phase');
        $this->reloadActivities();
    }

    public function render(): View
    {
        return view('lead-pipeline::kanban.lead-detail-modal', ['lead' => $this->lead]);
    }

    /** @return array<string, mixed> */
    private function activityRelations(): array
    {
        return [
            'activities' => fn ($q) => $q->latest()->limit($this->activitiesLimit),
            'activities.causer',
        ];
    }

    private function reloadActivities(): void
    {
        $this->lead?->load($this->activityRelations());
    }

    private function authorizeAccess(): void
    {
        if ( ! config('lead-pipeline.tenancy.enabled')) {
            return;
        }

        $teamFk   = config('lead-pipeline.tenancy.foreign_key');
        $tenantId = filament()->getTenant()?->getKey();

        if ($this->lead->board->{$teamFk} !== $tenantId && ! $this->lead->board->isSharedWith(filament()->getTenant())) {
            abort(403, 'Nicht autorisiert.');
        }
    }
}
