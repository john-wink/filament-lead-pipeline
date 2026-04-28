<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Aggregators;

use Carbon\CarbonInterface;
use JohnWink\FilamentLeadPipeline\Contracts\StatsAggregatorContract;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

class DefaultStatsAggregator implements StatsAggregatorContract
{
    /**
     * @return array<string, int>
     */
    public function aggregate(LeadBoard $board, CarbonInterface $period): array
    {
        $leadFk = LeadBoard::fkColumn('lead_board');

        $byPhaseType = $board->leads()
            ->join('lead_phases', 'lead_phases.uuid', '=', 'leads.lead_phase_uuid')
            ->selectRaw('lead_phases.type, COUNT(*) as cnt')
            ->groupBy('lead_phases.type')
            ->pluck('cnt', 'type')
            ->all();

        $countOf = static fn (LeadPhaseTypeEnum $type): int => (int) ($byPhaseType[$type->value] ?? 0);

        $total = (int) array_sum($byPhaseType);

        $newToday = (int) $board->leads()
            ->whereDate('leads.created_at', $period->toDateString())
            ->count();

        return [
            'total'       => $total,
            'new'         => $newToday,
            'qualified'   => $countOf(LeadPhaseTypeEnum::InProgress),
            'transferred' => $countOf(LeadPhaseTypeEnum::Won),
            'won'         => $countOf(LeadPhaseTypeEnum::Won),
            'lost'        => $countOf(LeadPhaseTypeEnum::Lost),
        ];
    }
}
