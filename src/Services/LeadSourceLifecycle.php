<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class LeadSourceLifecycle
{
    public function end(LeadSource $source, bool $deactivateBoard = false): void
    {
        $source->end();

        if ($deactivateBoard && $source->board) {
            $this->setBoardsActive(collect([$source->board]), false);
        }
    }

    /**
     * @param  Collection<int, LeadSource>  $sources
     */
    public function endMany(Collection $sources, bool $deactivateEmptiedBoards = true): int
    {
        $ended = $sources
            ->reject(fn (LeadSource $source): bool => $source->isEnded())
            ->filter(fn (LeadSource $source): bool => $source->end());

        if ($deactivateEmptiedBoards) {
            $this->setBoardsActive($this->boardsWithoutActiveGroupSources($ended), false);
        }

        return $ended->count();
    }

    public function reactivate(LeadSource $source, bool $activateBoard = false): void
    {
        $source->reactivate();

        if ($activateBoard && $source->board) {
            $this->setBoardsActive(collect([$source->board]), true);
        }
    }

    public function canDeactivateBoardOf(LeadSource $source): bool
    {
        return $this->canSwitchBoardOf($source, false);
    }

    public function canActivateBoardOf(LeadSource $source): bool
    {
        return $this->canSwitchBoardOf($source, true);
    }

    private function canSwitchBoardOf(LeadSource $source, bool $active): bool
    {
        $board = $source->board;
        $user  = auth()->user();

        return null !== $board
            && null !== $user
            && $active !== $board->is_active
            && $board->isAdmin($user);
    }

    /**
     * @param  Collection<int, LeadSource>  $sources
     * @return Collection<int, LeadBoard>
     */
    private function boardsWithoutActiveGroupSources(Collection $sources): Collection
    {
        return LeadBoard::query()
            ->whereKey($sources->pluck(LeadSource::fkColumn('lead_board'))->filter()->unique()->all())
            ->whereDoesntHave('sources', fn (Builder $query): Builder => $query->inActiveGroup())
            ->get();
    }

    /**
     * @param  Collection<int, LeadBoard>  $boards
     */
    private function setBoardsActive(Collection $boards, bool $active): int
    {
        return LeadBoard::setActiveByAdmin($boards, $active, auth()->user());
    }
}
