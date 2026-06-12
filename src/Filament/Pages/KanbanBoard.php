<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

class KanbanBoard extends Page
{
    public ?LeadBoard $board = null;

    public ?string $activeTab = 'board';

    public bool $showFilters = false;

    public string $search = '';

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $newLeadName = '';

    public string $newLeadEmail = '';

    public string $newLeadPhone = '';

    public ?string $newLeadAssignedUserId = null;

    public ?string $createInPhaseId = null;

    public bool $showCreateModal = false;

    /** Name des Bestandsleads mit gleicher E-Mail/Telefonnummer — steuert die Duplikat-Warnung im Modal. */
    public ?string $duplicateLeadName = null;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static string $view = 'lead-pipeline::filament.pages.kanban-board';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'lead-board/{board}';

    public function mount(LeadBoard $board): void
    {
        if ( ! $board->isAccessibleByTenant(filament()->getTenant())) {
            abort(403, 'Nicht autorisiert.');
        }

        $this->board   = $board;
        $this->filters = session("lead-pipeline.filters.{$board->getKey()}", []);

        // If the current user is a board admin and there are unassigned leads
        // in a list-type phase, start on that phase tab instead of the board.
        $user = auth()->user();
        if ($user && $board->isAdmin($user)) {
            $listPhaseWithUnassigned = $board->phases()
                ->where('display_type', LeadPhaseDisplayTypeEnum::List)
                ->whereHas('leads', fn ($q) => $q->whereNull('assigned_to'))
                ->ordered()
                ->first();

            if ($listPhaseWithUnassigned) {
                $this->activeTab = $listPhaseWithUnassigned->getKey();
            }
        }
    }

    #[Computed]
    public function isBoardAdmin(): bool
    {
        $user = auth()->user();

        return null !== $user && null !== $this->board && $this->board->isAdmin($user);
    }

    /**
     * Advisors = all assignable team users minus other board admins.
     *
     * Admins of the current board are excluded so the select truly offers advisors,
     * except for the currently logged-in user: an admin may also work a lead themselves.
     *
     * @return array<int|string, string>
     */
    #[Computed]
    public function advisorOptions(): array
    {
        if (null === $this->board) {
            return [];
        }

        $currentUserId = auth()->id();

        $otherAdminIds = $this->board->admins()
            ->pluck('lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'))
            ->reject(fn ($id) => $id === $currentUserId)
            ->all();

        return FilamentLeadPipelinePlugin::getAssignableUsers()
            ->reject(fn ($user) => in_array($user->getKey(), $otherAdminIds, true))
            ->mapWithKeys(fn ($user) => [$user->getKey() => $user->display_label])
            ->all();
    }

    public function getKanbanPhases(): Collection
    {
        return $this->board?->phases()
            ->kanban()
            ->ordered()
            ->get() ?? collect();
    }

    /**
     * Verbindungs-/Quellen-Gesundheit für Banner + Ampel: stille Lead-Ausfälle sichtbar machen.
     *
     * @return array{error_sources: int, connection_state: string}
     */
    #[Computed]
    public function connectionAlerts(): array
    {
        $errorSources = $this->board?->sources()
            ->where('status', \JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum::Error)
            ->count() ?? 0;

        $connectionState = 'ok';
        $connections     = \JohnWink\FilamentLeadPipeline\Models\FacebookConnection::query()
            ->where(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'), filament()->getTenant()?->getKey())
            ->get();

        foreach ($connections as $connection) {
            $state = $connection->healthState();

            if ('critical' === $state) {
                $connectionState = 'critical';
                break;
            }

            if ('warning' === $state) {
                $connectionState = 'warning';
            }
        }

        return ['error_sources' => $errorSources, 'connection_state' => $connectionState];
    }

    /**
     * Aggregierte Kopfzeilen-Stats in EINER Query statt COUNT/SUM pro Phase (N+1).
     *
     * @return array{leads: int, value: float}
     */
    #[Computed]
    public function boardStats(): array
    {
        if (null === $this->board) {
            return ['leads' => 0, 'value' => 0.0];
        }

        $kanbanPhaseKeys = $this->board->phases()->kanban()->pluck((new LeadPhase())->getKeyName());

        $totals = Lead::query()
            ->where(Lead::fkColumn('lead_board'), $this->board->getKey())
            ->whereIn(Lead::fkColumn('lead_phase'), $kanbanPhaseKeys)
            ->selectRaw('COUNT(*) as total_leads, COALESCE(SUM(value), 0) as total_value')
            ->first();

        return [
            'leads' => (int) $totals->total_leads,
            'value' => (float) $totals->total_value,
        ];
    }

    /**
     * „Meine Leads heute" — persönliche Tages-KPIs des eingeloggten Beraters
     * (1 Lead-Aggregat + 1 Distinct-Count auf Kontakt-Activities).
     *
     * @return array{new_today: int, contacted_today: int, won_week: int, open_mine: int, due_today: int}
     */
    #[Computed]
    public function myDayStats(): array
    {
        $userId = auth()->id();

        if (null === $userId || null === $this->board) {
            return ['new_today' => 0, 'contacted_today' => 0, 'won_week' => 0, 'open_mine' => 0, 'due_today' => 0];
        }

        $totals = Lead::query()
            ->where(Lead::fkColumn('lead_board'), $this->board->getKey())
            ->where('assigned_to', (string) $userId)
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_today, '
                . 'SUM(CASE WHEN status = ? AND updated_at >= ? THEN 1 ELSE 0 END) as won_week, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_mine, '
                . 'SUM(CASE WHEN status = ? AND reminder_at IS NOT NULL AND reminder_at <= ? THEN 1 ELSE 0 END) as due_today',
                [
                    now()->startOfDay(),
                    LeadStatusEnum::Won->value,
                    now()->startOfWeek(),
                    LeadStatusEnum::Active->value,
                    LeadStatusEnum::Active->value,
                    now()->endOfDay(),
                ],
            )
            ->first();

        $contactedToday = \JohnWink\FilamentLeadPipeline\Models\LeadActivity::query()
            ->whereIn('type', [LeadActivityTypeEnum::Call->value, LeadActivityTypeEnum::Email->value])
            ->where('causer_id', (string) $userId)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('lead', fn ($q) => $q->where(Lead::fkColumn('lead_board'), $this->board->getKey()))
            ->distinct()
            ->count(\JohnWink\FilamentLeadPipeline\Models\LeadActivity::fkColumn('lead'));

        return [
            'new_today'       => (int) $totals->new_today,
            'contacted_today' => $contactedToday,
            'won_week'        => (int) $totals->won_week,
            'open_mine'       => (int) $totals->open_mine,
            'due_today'       => (int) $totals->due_today,
        ];
    }

    public function getListPhases(): Collection
    {
        return $this->board->phases()->list()->ordered()->withCount('leads')->get();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }

    public function updatedFilters(): void
    {
        $cleaned       = array_filter($this->filters, fn ($v) => filled($v));
        $this->filters = $cleaned;
        session(["lead-pipeline.filters.{$this->board->getKey()}" => $cleaned]);
        $this->dispatch('filters-updated', filters: $cleaned);
    }

    /** Zentrale Suche über alle Spalten — ersetzt die frühere Suche pro Spalte. */
    public function updatedSearch(): void
    {
        $this->dispatch('search-updated', search: $this->search);
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        session()->forget("lead-pipeline.filters.{$this->board->getKey()}");
        $this->dispatch('filters-updated', filters: []);
    }

    #[On('create-lead')]
    #[On('open-create-modal')]
    public function openCreateModal(?string $phaseId = null): void
    {
        $this->createInPhaseId = $phaseId;
        $this->showCreateModal = true;
        $this->reset(['newLeadName', 'newLeadEmail', 'newLeadPhone', 'newLeadAssignedUserId', 'duplicateLeadName']);

        // Advisors (non-admins) are auto-assigned to leads they create themselves.
        if ( ! $this->isBoardAdmin) {
            $this->newLeadAssignedUserId = (string) auth()->id();
        }
    }

    public function createLead(bool $force = false): void
    {
        $this->validate([
            'newLeadName'           => 'required|string|max:255',
            'newLeadEmail'          => 'nullable|email|max:255',
            'newLeadPhone'          => 'nullable|string|max:50',
            'newLeadAssignedUserId' => 'nullable|string',
        ]);

        if ( ! $force) {
            $duplicate = $this->board->findDuplicateLead($this->newLeadEmail ?: null, $this->newLeadPhone ?: null);

            if ($duplicate) {
                $this->duplicateLeadName = $duplicate->name;

                return;
            }
        }

        $this->duplicateLeadName = null;

        $boardFk = LeadBoard::fkColumn('lead_board');
        $phaseFk = LeadPhase::fkColumn('lead_phase');

        $phaseId = $this->createInPhaseId
            ?? $this->board->phases()->kanban()->ordered()->first()?->getKey();

        $assignedTo = $this->isBoardAdmin
            ? ($this->newLeadAssignedUserId ?: null)
            : (string) auth()->id();

        $lead = Lead::query()->create([
            $boardFk      => $this->board->getKey(),
            $phaseFk      => $phaseId,
            'name'        => $this->newLeadName,
            'email'       => $this->newLeadEmail ?: null,
            'phone'       => $this->newLeadPhone ?: null,
            'status'      => LeadStatusEnum::Active,
            'sort'        => 0,
            'assigned_to' => $assignedTo,
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

            if ($this->board->{$teamFk} !== $tenantId && ! $this->board->isSharedWith(filament()->getTenant())) {
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

    /**
     * @param  array<int, string>  $orderedIds
     */
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

    public function getBoard(): LeadBoard
    {
        return $this->board;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getBoard()->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getBoard()->name;
    }
}
