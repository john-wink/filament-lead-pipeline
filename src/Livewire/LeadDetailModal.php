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
use JohnWink\FilamentLeadPipeline\Exceptions\LeadAlreadyTransferredException;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadTransferService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class LeadDetailModal extends Component
{
    private const int ACTIVITIES_PER_PAGE = 10;

    /** @var list<string> */
    private const array CALL_RESULTS = ['reached', 'voicemail', 'not_reached', 'callback'];

    public ?string $leadId = null;

    public bool $isOpen = false;

    public string $newNote = '';

    public string $reminderAt = '';

    public string $reminderNote = '';

    public int $activitiesLimit = self::ACTIVITIES_PER_PAGE;

    public ?string $transferTargetBoardId = null;

    public ?string $transferTargetPhaseId = null;

    public ?string $transferAssigneeId = null;

    public string $transferNote = '';

    public bool $showTransferForm = false;

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
        $this->reminderAt      = '';
        $this->reminderNote    = '';
        $this->resetErrorBag();
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
        $this->reset(['leadId', 'isOpen', 'newNote', 'activitiesLimit', 'reminderAt', 'reminderNote']);
        unset($this->lead);
    }

    /** Wiedervorlage setzen: Termin + optionale Notiz, nachvollziehbar als FollowUp-Activity. */
    public function setReminder(): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $this->validate(
            ['reminderAt' => 'required|date|after:now'],
            [],
            ['reminderAt' => __('lead-pipeline::lead-pipeline.reminder.label')],
        );

        $reminderAt = \Carbon\Carbon::parse($this->reminderAt);

        $this->lead->update([
            'reminder_at'          => $reminderAt,
            'reminder_note'        => $this->reminderNote ?: null,
            'reminder_notified_at' => null,
        ]);

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::FollowUp->value,
            'description' => __('lead-pipeline::lead-pipeline.reminder.activity_set', ['date' => $reminderAt->format('d.m.Y H:i')])
                . ($this->reminderNote ? ' — ' . $this->reminderNote : ''),
            'properties'  => ['reminder_at' => $reminderAt->toDateTimeString(), 'note' => $this->reminderNote ?: null],
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $this->reminderAt   = '';
        $this->reminderNote = '';
        $this->reloadActivities();
    }

    public function clearReminder(): void
    {
        if ( ! $this->lead || null === $this->lead->reminder_at) {
            return;
        }

        $this->authorizeAccess();

        $this->lead->update([
            'reminder_at'          => null,
            'reminder_note'        => null,
            'reminder_notified_at' => null,
        ]);

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::FollowUp->value,
            'description' => __('lead-pipeline::lead-pipeline.reminder.activity_cleared'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $this->reloadActivities();
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

    /** Quick-Menu nach dem Telefonat: ein Klick statt Notiz tippen. */
    public function recordCallResult(string $result): void
    {
        if ( ! $this->lead || ! in_array($result, self::CALL_RESULTS, true)) {
            return;
        }

        $this->authorizeAccess();

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Call->value,
            'description' => __('lead-pipeline::lead-pipeline.activity.call_result_' . $result),
            'properties'  => ['call_result' => $result],
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $this->reloadActivities();
    }

    public function logContact(string $channel): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        if (null !== $this->lead->logContactAttempt($channel)) {
            $this->reloadActivities();
        }
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

    public function markAsDisqualified(string $reason = ''): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $this->lead->update([
            'status' => LeadStatusEnum::Disqualified,
        ]);

        $this->lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Updated->value,
            'description' => '' !== $reason
                ? __('lead-pipeline::lead-pipeline.actions.disqualified_with_reason', ['reason' => $reason])
                : __('lead-pipeline::lead-pipeline.actions.disqualified_no_reason'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        $disqualifiedPhase = $this->boardPhases()->firstWhere('type', LeadPhaseTypeEnum::Disqualified);

        if ($disqualifiedPhase) {
            $fromPhase = $this->lead->phase;
            $this->lead->moveToPhase($disqualifiedPhase);
            $this->dispatch('phase-updated', phaseId: $fromPhase?->getKey());
            $this->dispatch('phase-updated', phaseId: $disqualifiedPhase->getKey());
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

        $this->attemptAutoConversion();

        $this->lead->load('phase');
        $this->reloadActivities();

        if (
            $this->lead->board?->transferEnabled()
            && ($this->lead->board->settings['prompt_on_won'] ?? false)
            && ! $this->lead->isTransferred()
        ) {
            $this->openTransferForm();
        }
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

    public function openTransferForm(): void
    {
        $this->showTransferForm      = true;
        $this->transferNote          = '';
        $this->transferTargetBoardId = null;
        $this->transferTargetPhaseId = null;
        $this->transferAssigneeId    = null;
        $this->resetErrorBag();
    }

    /** @return Collection<int, LeadBoard> */
    public function transferableBoards(): Collection
    {
        if ( ! $this->lead) {
            return collect();
        }

        $tenant = function_exists('filament') ? filament()->getTenant() : null;

        $query = LeadBoard::query()
            ->visibleToTenant($tenant)
            ->where('is_active', true)
            ->whereKeyNot($this->lead->{Lead::fkColumn('lead_board')});

        $filterClass = config('lead-pipeline.transfer.board_filter');
        if ($filterClass) {
            $query = app($filterClass)->apply($query, $tenant);
        }

        return $query->orderBy('name')->get();
    }

    public function transferToBoard(): void
    {
        if ( ! $this->lead) {
            return;
        }

        $this->authorizeAccess();

        $this->validate([
            'transferTargetBoardId' => 'required|string',
            'transferNote'          => 'required|string|min:3',
        ]);

        $target = $this->transferableBoards()->firstWhere(LeadBoard::pkColumn(), $this->transferTargetBoardId);

        if ( ! $target) {
            $this->addError('transferTargetBoardId', __('lead-pipeline::lead-pipeline.transfer.board_label'));

            return;
        }

        $phase = $this->transferTargetPhaseId ? LeadPhase::find($this->transferTargetPhaseId) : null;

        try {
            app(LeadTransferService::class)->transfer(
                $this->lead,
                $target,
                $phase,
                $this->transferAssigneeId,
                $this->transferNote,
            );
        } catch (LeadAlreadyTransferredException) {
            \Filament\Notifications\Notification::make()
                ->title(__('lead-pipeline::lead-pipeline.transfer.already_transferred'))
                ->warning()
                ->send();

            return;
        }

        $this->showTransferForm = false;
        $this->reloadActivities();
        $this->dispatch('phase-updated', phaseId: $this->lead->phase?->getKey());

        \Filament\Notifications\Notification::make()
            ->title(__('lead-pipeline::lead-pipeline.transfer.success', ['board' => $target->name]))
            ->success()
            ->send();
    }

    public function originLead(): ?Lead
    {
        return $this->lead?->originLead()?->load($this->activityRelations());
    }

    /** @return Collection<int, Lead> */
    public function forwardLeads(): Collection
    {
        return $this->lead
            ? $this->lead->transferredLeads()->with('board', 'phase')->get()
            : collect();
    }

    public function render(): View
    {
        return view('lead-pipeline::kanban.lead-detail-modal', ['lead' => $this->lead]);
    }

    /**
     * Config-gated (conversion.auto_convert_on_won): Nach „Gewonnen" automatisch
     * konvertieren — nur bei genau EINEM registrierten Converter, damit keine
     * implizite Converter-Wahl getroffen wird. Fehlschläge landen transparent
     * als Activity am Lead statt still zu versanden.
     */
    private function attemptAutoConversion(): void
    {
        if ( ! config('lead-pipeline.conversion.auto_convert_on_won', false)) {
            return;
        }

        if ($this->lead->conversions()->exists()) {
            return;
        }

        $service    = app(\JohnWink\FilamentLeadPipeline\Services\LeadConversionService::class);
        $converters = $service->getAvailableConverters();

        if (1 !== count($converters)) {
            return;
        }

        try {
            $service->convert($this->lead, (string) array_key_first($converters));
        } catch (Throwable $exception) {
            $this->lead->activities()->create([
                'type'        => LeadActivityTypeEnum::Updated->value,
                'description' => __('lead-pipeline::lead-pipeline.conversion.auto_failed', ['reason' => $exception->getMessage()]),
                'causer_type' => config('lead-pipeline.user_model'),
                'causer_id'   => auth()->id(),
            ]);
        }
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
