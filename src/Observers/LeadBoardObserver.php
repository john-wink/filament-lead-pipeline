<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Observers;

use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

class LeadBoardObserver
{
    /**
     * Jedes neue Board erhält verpflichtend eine terminale „Nicht qualifiziert"-Phase.
     * Idempotent: legt nur an, wenn noch keine disqualified-Phase existiert — so brechen
     * auch Factory-erzeugte Boards in Tests nicht.
     */
    public function created(LeadBoard $board): void
    {
        $this->ensureDisqualifiedPhase($board);
    }

    private function ensureDisqualifiedPhase(LeadBoard $board): void
    {
        $hasDisqualified = $board->phases()
            ->where('type', LeadPhaseTypeEnum::Disqualified->value)
            ->exists();

        if ($hasDisqualified) {
            return;
        }

        $maxSort = (int) $board->phases()->max('sort');

        $board->phases()->create([
            'name'         => __('lead-pipeline::lead-pipeline.phase_type.disqualified'),
            'type'         => LeadPhaseTypeEnum::Disqualified->value,
            'display_type' => LeadPhaseDisplayTypeEnum::List->value,
            'color'        => '#F59E0B',
            'sort'         => $maxSort + 1,
        ]);
    }
}
