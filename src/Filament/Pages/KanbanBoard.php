<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

class KanbanBoard extends Page
{
    public ?LeadBoard $board = null;

    public ?string $activeTab = 'board';

    public bool $showFilters = false;

    /** @var array<string, mixed> */
    public array $filters = [];

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static string $view = 'lead-pipeline::filament.pages.kanban-board';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'lead-board/{board}';

    public function mount(LeadBoard $board): void
    {
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

    public function clearFilters(): void
    {
        $this->filters = [];
        session()->forget("lead-pipeline.filters.{$this->board->getKey()}");
        $this->dispatch('filters-updated', filters: []);
    }

    public function getListPhases(): Collection
    {
        return $this->board->phases()->list()->ordered()->withCount('leads')->get();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
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
