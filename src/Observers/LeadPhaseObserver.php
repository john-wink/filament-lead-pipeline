<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Observers;

use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadBoardStructureChanged;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use RuntimeException;

class LeadPhaseObserver
{
    public function saving(LeadPhase $phase): void
    {
        if ( ! $phase->type instanceof LeadPhaseTypeEnum || ! $phase->type->isTerminal()) {
            return;
        }

        $phase->display_type = LeadPhaseDisplayTypeEnum::List;

        if ($this->otherTerminalExists($phase)) {
            throw new RuntimeException(__('lead-pipeline::lead-pipeline.board_edit.duplicate_terminal'));
        }
    }

    public function deleting(LeadPhase $phase): void
    {
        if ($phase->isForceDeleting()) {
            return;
        }

        if ($phase->leads()->exists()) {
            throw new RuntimeException(__('lead-pipeline::lead-pipeline.board_edit.phase_delete_has_leads'));
        }

        if ($phase->type instanceof LeadPhaseTypeEnum && $phase->type->isTerminal() && ! $this->otherTerminalExists($phase)) {
            throw new RuntimeException(__('lead-pipeline::lead-pipeline.board_edit.phase_delete_last_terminal'));
        }
    }

    public function created(LeadPhase $phase): void
    {
        $this->audit($phase, 'phase.added');
    }

    public function updated(LeadPhase $phase): void
    {
        $this->audit($phase, 'phase.updated');
    }

    public function deleted(LeadPhase $phase): void
    {
        $this->audit($phase, 'phase.removed');
    }

    private function audit(LeadPhase $phase, string $change): void
    {
        if ( ! auth()->check() || ! $phase->board) {
            return;
        }

        LeadBoardStructureChanged::dispatch(
            $phase->board,
            $change,
            ['name' => $phase->name, 'type' => $phase->type?->value, 'conversion_target' => $phase->conversion_target],
            auth()->user(),
        );
    }

    private function otherTerminalExists(LeadPhase $phase): bool
    {
        $boardFk = LeadPhase::fkColumn('lead_board');

        return LeadPhase::query()
            ->where($boardFk, $phase->{$boardFk})
            ->where('type', $phase->type->value)
            ->when($phase->getKey(), fn ($q) => $q->whereKeyNot($phase->getKey()))
            ->exists();
    }
}
