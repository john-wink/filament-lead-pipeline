<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Aggregators\DefaultStatsAggregator;
use JohnWink\FilamentLeadPipeline\Contracts\StatsAggregatorContract;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardStat;
use Throwable;

class LeadBoardStatsRefresher implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ?Carbon $period = null,
    ) {}

    public function handle(): void
    {
        $period     = $this->period ?? Carbon::today();
        $aggregator = $this->resolveAggregator();
        $periodKey  = $period->toDateString();

        LeadBoard::query()
            ->where('is_active', true)
            ->cursor()
            ->each(function (LeadBoard $board) use ($period, $periodKey, $aggregator): void {
                $existing = LeadBoardStat::query()
                    ->where('lead_board_uuid', $board->uuid)
                    ->whereDate('period_date', $periodKey)
                    ->first();

                $counts = $aggregator->aggregate($board, $period);

                if ($existing) {
                    $existing->update(['counts' => $counts]);

                    return;
                }

                LeadBoardStat::query()->create([
                    'lead_board_uuid' => $board->uuid,
                    'period_date'     => $periodKey,
                    'counts'          => $counts,
                ]);
            });
    }

    protected function resolveAggregator(): StatsAggregatorContract
    {
        try {
            $plugin = filament()->getPlugin('filament-lead-pipeline');
            $custom = $plugin->getStatsAggregator();
            if (null !== $custom) {
                return $custom;
            }
        } catch (Throwable) {
        }

        return new DefaultStatsAggregator();
    }
}
