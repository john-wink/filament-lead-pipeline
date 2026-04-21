<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

#[Isolate]
class KanbanPhaseColumn extends Component
{
    public string $phaseId = '';

    public ?LeadPhase $phase = null;

    public int $perPage = 20;

    public string $search = '';

    public string $sortBy = 'sort';

    public bool $initialized = false;

    /** @var array<string, mixed> */
    public array $filters = [];

    public function mount(string $phaseId): void
    {
        $this->phaseId = $phaseId;
        $this->phase   = LeadPhase::findOrFail($phaseId);
        $this->perPage = (int) config('lead-pipeline.kanban.leads_per_page', 20);
    }

    public function init(): void
    {
        $this->initialized = true;
    }

    #[On('phase-updated')]
    public function refreshIfMatch(string $phaseId): void
    {
        if ($phaseId === $this->phaseId) {
            $this->perPage = (int) config('lead-pipeline.kanban.leads_per_page', 20);
        }
    }

    #[On('filters-updated')]
    public function applyFilters(array $filters): void
    {
        $this->filters = $filters;
        $this->perPage = (int) config('lead-pipeline.kanban.leads_per_page', 20);
    }

    public function loadMore(): void
    {
        $this->perPage += (int) config('lead-pipeline.kanban.leads_per_page', 20);
    }

    public function assignUser(string $leadId, string $userId): void
    {
        $lead = Lead::with(['phase', 'board'])->find($leadId);
        if ( ! $lead) {
            return;
        }

        $lead->update([
            'assigned_to' => filled($userId) ? $userId : null,
        ]);

        $assigneeName = filled($userId)
            ? config('lead-pipeline.user_model')::find($userId)?->name ?? __('lead-pipeline::lead-pipeline.field.unknown')
            : null;

        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Assignment->value,
            'description' => $assigneeName
                ? __('lead-pipeline::lead-pipeline.actions.assigned_to_name', ['name' => $assigneeName])
                : __('lead-pipeline::lead-pipeline.actions.assignment_removed'),
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);

        // Auto-move from Open to first InProgress
        if (filled($userId) && $lead->phase) {
            $currentPhase = $lead->phase;
            if (LeadPhaseTypeEnum::Open === $currentPhase->type) {
                $firstInProgress = $lead->board->phases()
                    ->where('type', LeadPhaseTypeEnum::InProgress)
                    ->ordered()
                    ->first();

                if ($firstInProgress) {
                    $lead->moveToPhase($firstInProgress);
                    $this->dispatch('phase-updated', phaseId: $currentPhase->getKey());
                    $this->dispatch('phase-updated', phaseId: $firstInProgress->getKey());
                }
            }
        }

        $this->dispatch('phase-updated', phaseId: $this->phaseId);
    }

    public function render(): View
    {
        if ( ! $this->initialized) {
            return view('lead-pipeline::kanban.phase-column', [
                'leads'           => collect(),
                'totalCount'      => 0,
                'hasMore'         => false,
                'loading'         => true,
                'isAdmin'         => false,
                'assignableUsers' => collect(),
            ]);
        }

        // Load board + admin check ONCE for the entire column
        if ( ! $this->phase->relationLoaded('board')) {
            $this->phase->load('board');
        }
        $board   = $this->phase->board;
        $user    = auth()->user();
        $isAdmin = $board && $user && $board->isAdmin($user);

        $query = Lead::query()
            ->where(Lead::fkColumn('lead_phase'), $this->phaseId)
            ->with(['source', 'assignedUser', 'fieldValues.definition']);

        // Dynamic sort
        $query = match ($this->sortBy) {
            'newest'     => $query->orderByDesc('created_at'),
            'oldest'     => $query->orderBy('created_at'),
            'value_desc' => $query->orderByRaw('CASE WHEN value IS NULL THEN 1 ELSE 0 END, value DESC'),
            'name_asc'   => $query->orderBy('name'),
            default      => $query->ordered(),
        };

        if ('' !== $this->search) {
            $searchTerm = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchTerm): void {
                $q->where('name', 'like', $searchTerm)
                    ->orWhere('email', 'like', $searchTerm)
                    ->orWhere('phone', 'like', $searchTerm);
            });
        }

        if (filled($this->filters['source_id'] ?? null)) {
            $query->where(Lead::fkColumn('lead_source'), $this->filters['source_id']);
        }
        if (filled($this->filters['assigned_to'] ?? null)) {
            $query->where('assigned_to', $this->filters['assigned_to']);
        }
        if (filled($this->filters['status'] ?? null)) {
            $query->where('status', $this->filters['status']);
        }
        if (filled($this->filters['value_min'] ?? null)) {
            $query->where('value', '>=', $this->filters['value_min']);
        }
        if (filled($this->filters['value_max'] ?? null)) {
            $query->where('value', '<=', $this->filters['value_max']);
        }
        if (filled($this->filters['created_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $this->filters['created_from']);
        }
        if (filled($this->filters['created_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $this->filters['created_to']);
        }

        // Role-based visibility
        if ($board && $user) {
            $query->visibleTo($user, $board);
        }

        $totalCount = $query->count();
        $leads      = $query->take($this->perPage)->get();
        $hasMore    = $totalCount > $leads->count();

        // Load assignable users ONCE for the whole column
        $assignableUsers = $isAdmin ? $this->getAssignableUsersOnce() : collect();

        return view('lead-pipeline::kanban.phase-column', [
            'leads'           => $leads,
            'totalCount'      => $totalCount,
            'hasMore'         => $hasMore,
            'loading'         => false,
            'isAdmin'         => $isAdmin,
            'assignableUsers' => $assignableUsers,
        ]);
    }

    /** Cached per request — query runs max once per column render */
    private function getAssignableUsersOnce(): Collection
    {
        static $cached = null;

        return $cached ??= FilamentLeadPipelinePlugin::getAssignableUsers();
    }
}
