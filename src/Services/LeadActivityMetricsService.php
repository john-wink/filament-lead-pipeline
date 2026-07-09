<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

class LeadActivityMetricsService
{
    /**
     * @return array{
     *     total: int, responded: int, avg_minutes: ?float,
     *     buckets: array{under_1h: int, h1_24: int, h24_48: int, over_48h: int},
     *     sla_pct: float
     * }
     */
    public function responseStats(Builder $leads, CarbonImmutable $from, CarbonImmutable $to, int $slaMinutes = 60): array
    {
        $rows = (clone $leads)
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'first_response_at']);

        $total     = $rows->count();
        $buckets   = ['under_1h' => 0, 'h1_24' => 0, 'h24_48' => 0, 'over_48h' => 0];
        $minutes   = [];
        $withinSla = 0;

        foreach ($rows as $row) {
            if (null === $row->first_response_at) {
                continue;
            }

            // absolute: true — Carbon 3's diffInMinutes is signed by default; a
            // backdated first_response_at (e.g. from an import) must never yield a
            // negative diff that silently reads as under_1h / SLA-compliant.
            $mins      = CarbonImmutable::parse($row->created_at)->diffInMinutes(CarbonImmutable::parse($row->first_response_at), absolute: true);
            $minutes[] = $mins;

            if ($mins <= $slaMinutes) {
                $withinSla++;
            }

            match (true) {
                $mins < 60   => $buckets['under_1h']++,
                $mins < 1440 => $buckets['h1_24']++,
                $mins < 2880 => $buckets['h24_48']++,
                default      => $buckets['over_48h']++,
            };
        }

        $responded = count($minutes);

        return [
            'total'       => $total,
            'responded'   => $responded,
            'avg_minutes' => $responded > 0 ? round(array_sum($minutes) / $responded, 1) : null,
            'buckets'     => $buckets,
            'sla_pct'     => $responded > 0 ? round($withinSla / $responded * 100, 1) : 0.0,
        ];
    }

    /**
     * Snapshot of the current state of active leads (not scoped to a period).
     *
     * @return array{overdue_followups: int, next_step_rate: float, untouched: int, avg_contact_attempts: float}
     */
    public function operationsStats(Builder $leads): array
    {
        $leadFk       = Lead::fkColumn('lead');
        $contactTypes = [LeadActivityTypeEnum::Call->value, LeadActivityTypeEnum::Email->value];
        $touchTypes   = [...$contactTypes, LeadActivityTypeEnum::Note->value];
        $staleDays    = (int) config('lead-pipeline.kanban.stale_warning_days', 7);

        $activeLeads = (clone $leads)->where('status', LeadStatusEnum::Active);
        $activeCount = (clone $activeLeads)->count();

        $overdue      = (clone $activeLeads)->whereNotNull('reminder_at')->where('reminder_at', '<', now())->count();
        $withNextStep = (clone $activeLeads)->whereNotNull('reminder_at')->count();

        $untouched = (clone $activeLeads)
            ->where('created_at', '<', now()->subDays($staleDays))
            ->whereDoesntHave('activities', fn (Builder $q): Builder => $q->whereIn('type', $touchTypes))
            ->count();

        $leadIds      = (clone $activeLeads)->pluck(Lead::pkColumn());
        $attemptCount = LeadActivity::query()
            ->whereIn($leadFk, $leadIds)
            ->whereIn('type', $contactTypes)
            ->count();

        return [
            'overdue_followups'    => $overdue,
            'next_step_rate'       => $activeCount > 0 ? round($withNextStep / $activeCount * 100, 1) : 0.0,
            'untouched'            => $untouched,
            'avg_contact_attempts' => $activeCount > 0 ? round($attemptCount / $activeCount, 1) : 0.0,
        ];
    }

    /**
     * Verweildauer je Phase, ermittelt aus aufeinanderfolgenden Moved-Activities
     * (Eintritt in eine Phase bis zum nächsten Move). "Überaltert" = Verweildauer
     * über dem 1,5-fachen Median der Phase.
     *
     * @return list<array{phase_id: string, label: string, avg_days: float, overaged_pct: float}>
     */
    public function stageDwell(Builder $leads): array
    {
        $leadIds = (clone $leads)->pluck(Lead::pkColumn());

        $moves = LeadActivity::query()
            ->whereIn(Lead::fkColumn('lead'), $leadIds)
            ->where('type', LeadActivityTypeEnum::Moved->value)
            ->orderBy(Lead::fkColumn('lead'))
            ->orderBy('created_at')
            ->get([Lead::fkColumn('lead'), 'properties', 'created_at']);

        /** @var array<string, list<float>> $durations phaseId => list of dwell days */
        $durations = [];
        foreach ($moves->groupBy(Lead::fkColumn('lead')) as $leadMoves) {
            $ordered = $leadMoves->values();
            for ($i = 0; $i < $ordered->count() - 1; $i++) {
                $phaseId = $ordered[$i]->properties['new_phase'] ?? null;
                if (blank($phaseId)) {
                    continue;
                }

                // absolute: true — Carbon 3's diffInDays is signed by default; activities
                // are ordered by created_at above, but a clock skew or backfilled import
                // must never yield a negative dwell that silently reads as zero/overaged-free.
                $days = CarbonImmutable::parse($ordered[$i]->created_at)
                    ->diffInDays(CarbonImmutable::parse($ordered[$i + 1]->created_at), absolute: true);

                $durations[$phaseId][] = $days;
            }
        }

        $phaseNames = LeadPhase::query()
            ->whereIn(LeadPhase::pkColumn(), array_keys($durations))
            ->pluck('name', LeadPhase::pkColumn());

        $result = [];
        foreach ($durations as $phaseId => $list) {
            sort($list);
            $median    = $list[(int) floor((count($list) - 1) / 2)];
            $threshold = $median * 1.5;
            $overaged  = count(array_filter($list, fn (float $d): bool => $d > $threshold));

            $result[] = [
                'phase_id'     => (string) $phaseId,
                'label'        => (string) ($phaseNames[$phaseId] ?? '—'),
                'avg_days'     => round(array_sum($list) / count($list), 1),
                'overaged_pct' => round($overaged / count($list) * 100, 1),
            ];
        }

        return $result;
    }

    /**
     * Leads je Phase (in Sortierreihenfolge) mit Absprungrate zur Vorstufe.
     *
     * @return list<array{label: string, count: int, drop_pct: float}>
     */
    public function funnel(LeadBoard $board): array
    {
        $phases = $board->phases()->ordered()->get();
        $result = [];
        $prev   = null;

        foreach ($phases as $phase) {
            $count   = $phase->leads()->count();
            $dropPct = (null !== $prev && $prev > 0) ? round(($prev - $count) / $prev * 100, 1) : 0.0;

            $result[] = [
                'label'    => (string) $phase->name,
                'count'    => $count,
                'drop_pct' => max($dropPct, 0.0),
            ];

            $prev = $count;
        }

        return $result;
    }

    /**
     * Verlustgründe gruppiert nach Häufigkeit, absteigend. Leere/fehlende Gründe
     * werden ausgeschlossen, da sie keine auswertbare Information liefern.
     *
     * @return list<array{reason: string, count: int}>
     */
    public function lossReasons(Builder $leads): array
    {
        return (clone $leads)
            ->whereNotNull('lost_reason')
            ->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, COUNT(*) as cnt')
            ->groupBy('lost_reason')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($row): array => ['reason' => (string) $row->lost_reason, 'count' => (int) $row->cnt])
            ->all();
    }
}
