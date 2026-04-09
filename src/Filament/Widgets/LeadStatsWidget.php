<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;

class LeadStatsWidget extends StatsOverviewWidget
{
    public ?string $boardId = null;

    protected function getStats(): array
    {
        $query = Lead::query();

        if ($this->boardId) {
            $query->where(Lead::fkColumn('lead_board'), $this->boardId);
        } elseif (filament()->getTenant()) {
            $query->whereHas('board', fn ($q) => $q->where(
                config('lead-pipeline.tenancy.foreign_key'),
                filament()->getTenant()->getKey()
            ));
        }

        $totalCount     = (clone $query)->count();
        $activeCount    = (clone $query)->where('status', LeadStatusEnum::Active)->count();
        $wonCount       = (clone $query)->where('status', LeadStatusEnum::Won)->count();
        $lostCount      = (clone $query)->where('status', LeadStatusEnum::Lost)->count();
        $convertedCount = (clone $query)->where('status', LeadStatusEnum::Converted)->count();
        $totalValue     = (clone $query)->sum('value');
        $wonValue       = (clone $query)->where('status', LeadStatusEnum::Won)->sum('value');

        return [
            Stat::make(__('lead-pipeline::lead-pipeline.stats.total'), number_format($totalCount))
                ->description($activeCount . ' ' . __('lead-pipeline::lead-pipeline.stats.active'))
                ->icon('heroicon-o-users')
                ->color('gray'),
            Stat::make(__('lead-pipeline::lead-pipeline.stats.won'), number_format($wonCount))
                ->description($totalCount > 0 ? round(($wonCount / $totalCount) * 100, 1) . '% ' . __('lead-pipeline::lead-pipeline.stats.won_rate') : '0%')
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make(__('lead-pipeline::lead-pipeline.stats.lost'), number_format($lostCount))
                ->description($totalCount > 0 ? round(($lostCount / $totalCount) * 100, 1) . '% ' . __('lead-pipeline::lead-pipeline.stats.lost_rate') : '0%')
                ->icon('heroicon-o-x-circle')
                ->color('danger'),
            Stat::make(__('lead-pipeline::lead-pipeline.stats.converted'), number_format($convertedCount))
                ->icon('heroicon-o-arrow-right-circle')
                ->color('info'),
            Stat::make(__('lead-pipeline::lead-pipeline.stats.total_value'), number_format((float) $totalValue, 2, ',', '.') . ' €')
                ->description(__('lead-pipeline::lead-pipeline.stats.won_value') . ': ' . number_format((float) $wonValue, 2, ',', '.') . ' €')
                ->icon('heroicon-o-currency-euro')
                ->color('warning'),
        ];
    }
}
