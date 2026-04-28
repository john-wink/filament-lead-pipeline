<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use Carbon\CarbonInterface;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

/**
 * Computes the daily counts payload for a LeadBoard snapshot.
 *
 * The default implementation provides standard buckets (total, new,
 * qualified, transferred, won, lost). Apps can swap in their own
 * aggregator to add domain-specific buckets via the plugin's fluent
 * statsAggregator() configuration.
 */
interface StatsAggregatorContract
{
    /**
     * Returns the counts payload to be persisted in lead_board_stats.counts
     * for the given board and period.
     *
     * @return array<string, mixed>
     */
    public function aggregate(LeadBoard $board, CarbonInterface $period): array;
}
