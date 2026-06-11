<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\DTOs\ReportMetricsData;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportAdSource;
use JohnWink\FilamentLeadPipeline\Models\MetaAdCreative;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;
use JohnWink\FilamentLeadPipeline\Models\MetaReachRange;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

class ReportMetricsService
{
    public function metrics(LeadReport $report, ReportDateRange $range): ReportMetricsData
    {
        $current  = $this->rawTotals($report, $range);
        $previous = ReportDatePresetEnum::AllTime === $range->preset
            ? null
            : $this->rawTotals($report, $range->previous());

        $deltas = null === $previous ? [] : collect($current)
            ->mapWithKeys(fn (float|int $value, string $key): array => [
                $key => ($previous[$key] ?? 0) > 0
                    ? round((($value - $previous[$key]) / $previous[$key]) * 100, 1)
                    : null,
            ])->all();

        // Kosten/Anfrage-Delta aus den rawTotals beider Zeiträume (cpi = spend / inquiries)
        $costPerInquiryOf = fn (array $totals): ?float => $totals['inquiries'] > 0 ? $totals['spend'] / $totals['inquiries'] : null;
        $currentCpi       = $costPerInquiryOf($current);
        $previousCpi      = null === $previous ? null : $costPerInquiryOf($previous);

        if (null !== $currentCpi && null !== $previousCpi && $previousCpi > 0.0) {
            $deltas['cost_per_inquiry'] = round((($currentCpi - $previousCpi) / $previousCpi) * 100, 1);
        }

        return new ReportMetricsData(
            impressions: (int) $current['impressions'],
            reach: $this->reach($report, $range),
            spend: (float) $current['spend'],
            clicks: (int) $current['clicks'],
            linkClicks: (int) $current['link_clicks'],
            inquiries: (int) $current['inquiries'],
            costPerInquiry: $current['inquiries'] > 0 ? round($current['spend'] / $current['inquiries'], 2) : null,
            qualified: (int) $current['qualified'],
            won: (int) $current['won'],
            costPerWon: $current['won'] > 0 ? round($current['spend'] / $current['won'], 2) : null,
            deltas: $deltas,
        );
    }

    /** @return array<int, array{date: string, inquiries: int, link_clicks: int}> */
    public function trend(LeadReport $report, ReportDateRange $range): array
    {
        $linkClicksByDate = $this->snapshotQuery($report, $range)
            ->selectRaw('date, SUM(link_clicks) as link_clicks')
            ->groupBy('date')->pluck('link_clicks', 'date');

        $inquiriesByDate = $this->leadQuery($report, $range)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')->pluck('total', 'day');

        $days = [];
        for ($date = $range->from; $date->lte($range->till); $date = $date->addDay()) {
            $key    = $date->toDateString();
            $days[] = [
                'date'      => $key,
                'inquiries' => (int) ($inquiriesByDate[$key] ?? 0),
                // date ist dank Set-Mutator als Y-m-d-String gespeichert; selectRaw liefert die Keys roh → direkter Zugriff
                'link_clicks' => (int) ($linkClicksByDate[$key] ?? 0),
            ];
        }

        return $days;
    }

    /** @return array{male: int, female: int, unknown: int}|null */
    public function genderBreakdown(LeadReport $report, ReportDateRange $range): ?array
    {
        $rows = $this->snapshotQuery($report, $range, breakdownType: 'gender')
            ->selectRaw('breakdown_value, SUM(impressions) as impressions')
            ->groupBy('breakdown_value')->pluck('impressions', 'breakdown_value');

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'male'    => (int) ($rows['male'] ?? 0),
            'female'  => (int) ($rows['female'] ?? 0),
            'unknown' => (int) ($rows['unknown'] ?? 0),
        ];
    }

    /** @return array<int, array{key: string, label: string, value: int, cost_per: float|null}> */
    public function funnel(LeadReport $report, ReportDateRange $range): array
    {
        $totals = $this->rawTotals($report, $range);

        $stage = fn (string $key, string $label, int $value): array => [
            'key'      => $key,
            'label'    => $label,
            'value'    => $value,
            'cost_per' => $value > 0 ? round($totals['spend'] / $value, 2) : null,
        ];

        return [
            $stage('impressions', __('lead-pipeline::reports.funnel.impressions'), (int) $totals['impressions']),
            $stage('link_clicks', __('lead-pipeline::reports.funnel.link_clicks'), (int) $totals['link_clicks']),
            $stage('inquiries', __('lead-pipeline::reports.funnel.inquiries'), (int) $totals['inquiries']),
            $stage('qualified', __('lead-pipeline::reports.funnel.qualified'), (int) $totals['qualified']),
            $stage('won', __('lead-pipeline::reports.funnel.won'), (int) $totals['won']),
        ];
    }

    public function reach(LeadReport $report, ReportDateRange $range): ?int
    {
        if ($report->adSources->isEmpty()) {
            return null;
        }

        $total = 0;
        $found = false;

        foreach ($report->adSources as $source) {
            $row = ReportDatePresetEnum::Custom === $range->preset
                ? $this->customReachRow($source, $range)
                : MetaReachRange::query()
                    ->where('ad_account_id', $source->ad_account_id)
                    ->where('campaign_key', MetaReachRange::campaignKey($source->campaign_ids))
                    ->where('preset', $range->preset->value)
                    ->first();

            if (null !== $row) {
                $total += (int) $row->reach;
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    /** @return Collection<int, MetaAdCreative> */
    public function creatives(LeadReport $report): Collection
    {
        if ($report->adSources->isEmpty()) {
            return new Collection();
        }

        return MetaAdCreative::query()
            ->whereIn('ad_account_id', $report->adSources->pluck('ad_account_id')->unique())
            ->where(function (Builder $query) use ($report): void {
                foreach ($report->adSources as $source) {
                    if (null !== $source->campaign_ids && [] !== $source->campaign_ids) {
                        $query->orWhereIn('campaign_id', $source->campaign_ids);
                    } else {
                        $query->orWhere('ad_account_id', $source->ad_account_id);
                    }
                }
            })
            ->whereNotNull('image_path')
            ->orderByDesc('last_synced_at')
            ->limit(12)
            ->get();
    }

    public function lastSyncedAt(LeadReport $report): ?CarbonInterface
    {
        return $report->adSources->max('last_synced_at');
    }

    /**
     * Custom-Zeiträume (Spec §4): Cache-Lookup in meta_reach_ranges; Treffer gilt, wenn der
     * Zeitraum abgeschlossen ist (date_till < heute → unbegrenzt gültig) oder fetched_at < 6 h alt.
     * Sonst on-demand-Fetch je Konto und Upsert in den Cache.
     */
    private function customReachRow(LeadReportAdSource $source, ReportDateRange $range): ?MetaReachRange
    {
        $campaignKey = MetaReachRange::campaignKey($source->campaign_ids);

        $cached = MetaReachRange::query()
            ->where('ad_account_id', $source->ad_account_id)
            ->where('campaign_key', $campaignKey)
            ->where('preset', ReportDatePresetEnum::Custom->value)
            ->where('date_from', $range->from->toDateString())
            ->where('date_till', $range->till->toDateString())
            ->first();

        $isFinished = $range->till->lt(CarbonImmutable::now()->startOfDay());

        if (null !== $cached && ($isFinished || $cached->fetched_at->gt(now()->subHours(6)))) {
            return $cached;
        }

        try {
            $reach = app(FacebookGraphService::class)->getAdAccountReach(
                $source->ad_account_id,
                $source->connection->access_token,
                ['since' => $range->from->toDateString(), 'until' => $range->till->toDateString()],
                $source->campaign_ids,
            );
        } catch (RequestException) {
            return $cached; // API-Fehler: alter Cache-Stand oder null
        }

        return MetaReachRange::query()->updateOrCreate(
            [
                'ad_account_id' => $source->ad_account_id,
                'campaign_key'  => $campaignKey,
                'preset'        => ReportDatePresetEnum::Custom->value,
                'date_from'     => $range->from->toDateString(),
                'date_till'     => $range->till->toDateString(),
            ],
            ['reach' => $reach, 'fetched_at' => now()],
        );
    }

    /** @return array{impressions: int, spend: float, clicks: int, link_clicks: int, inquiries: int, qualified: int, won: int} */
    private function rawTotals(LeadReport $report, ReportDateRange $range): array
    {
        $snapshot = $this->snapshotQuery($report, $range)
            ->selectRaw('COALESCE(SUM(impressions),0) i, COALESCE(SUM(spend),0) s, COALESCE(SUM(clicks),0) c, COALESCE(SUM(link_clicks),0) lc')
            ->first();

        $mapping = $report->funnel_mapping ?? [
            'qualified' => [LeadPhaseTypeEnum::InProgress->value, LeadPhaseTypeEnum::Won->value],
            'won'       => [LeadPhaseTypeEnum::Won->value],
        ];

        $countByPhaseTypes = fn (array $types): int => $this->leadQuery($report, $range)
            ->whereHas('phase', fn (Builder $query): Builder => $query->whereIn('type', $types))
            ->count();

        return [
            'impressions' => (int) $snapshot->i,
            'spend'       => (float) $snapshot->s,
            'clicks'      => (int) $snapshot->c,
            'link_clicks' => (int) $snapshot->lc,
            'inquiries'   => $this->leadQuery($report, $range)->count(),
            'qualified'   => $countByPhaseTypes($mapping['qualified'] ?? []),
            'won'         => $countByPhaseTypes($mapping['won'] ?? []),
        ];
    }

    private function snapshotQuery(LeadReport $report, ReportDateRange $range, string $breakdownType = 'none'): Builder
    {
        $query = MetaInsightSnapshot::query()
            ->where('breakdown_type', $breakdownType)
            ->whereBetween('date', [$range->from->toDateString(), $range->till->toDateString()])
            ->where(function (Builder $query) use ($report): void {
                foreach ($report->adSources as $source) {
                    $query->orWhere(function (Builder $inner) use ($source): void {
                        $inner->where('ad_account_id', $source->ad_account_id);

                        if (null !== $source->campaign_ids && [] !== $source->campaign_ids) {
                            $inner->whereIn('campaign_id', $source->campaign_ids);
                        }
                    });
                }
            });

        // Report ohne Ad-Quellen: leeres Ergebnis statt Voll-Scan
        if ($report->adSources->isEmpty()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function leadQuery(LeadReport $report, ReportDateRange $range): Builder
    {
        // Plugin-First: id-Modus kompatibel (konfigurierbarer Primary Key / FK statt hartem 'uuid')
        return Lead::query()
            ->whereIn(Lead::fkColumn('lead_board'), $report->boards->pluck((new LeadBoard())->getKeyName()))
            ->whereBetween('created_at', [$range->from->startOfDay(), $range->till->endOfDay()]);
    }
}
