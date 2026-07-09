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
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;

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

    /**
     * Call/Email-Aktivitäten nach Wochentag (Mo–Sa) × 6 Zeitslots (8–20 Uhr).
     * Aktivitäten außerhalb der Slot-Stunden oder außerhalb von Mo–Sa werden verworfen.
     *
     * @return array{slots: list<string>, days: list<string>, matrix: list<list<int>>}
     */
    public function contactHeatmap(Builder $leads, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $slots  = ['8–10', '10–12', '12–14', '14–16', '16–18', '18–20'];
        $days   = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $matrix = array_fill(0, 6, array_fill(0, 6, 0));

        $leadIds    = (clone $leads)->pluck(Lead::pkColumn());
        $activities = LeadActivity::query()
            ->whereIn(Lead::fkColumn('lead'), $leadIds)
            ->whereIn('type', [LeadActivityTypeEnum::Call->value, LeadActivityTypeEnum::Email->value])
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at']);

        foreach ($activities as $activity) {
            $dow  = (int) $activity->created_at->dayOfWeekIso; // 1 = Mon … 7 = Sun
            $hour = (int) $activity->created_at->hour;

            if ($dow > 6 || $hour < 8 || $hour >= 20) {
                continue;
            }

            $slotIndex = intdiv($hour - 8, 2);
            $matrix[$dow - 1][$slotIndex]++;
        }

        return ['slots' => $slots, 'days' => $days, 'matrix' => $matrix];
    }

    /**
     * Pipeline-Velocity: offene Leads × Gewinnrate × Ø-Wert ÷ Ø-Zykluszeit.
     * Ø-Zykluszeit = Ø Tage von created_at bis converted_at (nur Leads mit gesetztem converted_at).
     *
     * @return array{open: int, win_rate: float, avg_value: float, cycle_days: float, velocity: float}
     */
    public function pipelineVelocity(Builder $leads): array
    {
        $open    = (clone $leads)->where('status', LeadStatusEnum::Active)->count();
        $won     = (clone $leads)->where('status', LeadStatusEnum::Won)->count();
        $lost    = (clone $leads)->where('status', LeadStatusEnum::Lost)->count();
        $winRate = ($won + $lost) > 0 ? $won / ($won + $lost) : 0.0;

        $avgValue = (float) ((clone $leads)->where('value', '>', 0)->avg('value') ?? 0);

        $cycleDays = (float) ((clone $leads)
            ->whereNotNull('converted_at')
            ->get(['created_at', 'converted_at'])
            ->map(fn (Lead $lead): float => (float) CarbonImmutable::parse($lead->created_at)->diffInDays(CarbonImmutable::parse($lead->converted_at), absolute: true))
            ->avg() ?? 0);

        $velocity = $cycleDays > 0 ? $open * $winRate * $avgValue / $cycleDays : 0.0;

        return [
            'open'       => $open,
            'win_rate'   => round($winRate * 100, 1),
            'avg_value'  => round($avgValue, 2),
            'cycle_days' => round($cycleDays, 1),
            'velocity'   => round($velocity, 2),
        ];
    }

    /**
     * Wirtschaftlichkeit je Lead-Quelle inkl. Ad-Kosten aus Meta-Insights (Task 13).
     * INNER JOIN — Quellen ohne zugeordnete Leads erscheinen nicht in der Liste.
     *
     * Näherung bei den Kosten: `meta_insight_snapshots.spend` liegt nur auf
     * Campaign-Ebene vor (keine Ad-ID-Spalte). Für jede Quelle wird der Spend
     * über alle Kampagnen summiert, die von ihren Leads via `source_campaign_id`
     * referenziert werden. Teilt sich eine Kampagne mehrere Quellen, wird ihr
     * Spend JEDER dieser Quellen voll angerechnet (Über-Attribution) — die Daten
     * kennen keine 1:1-Zuordnung Kampagne↔Quelle. `breakdown_type = 'none'`
     * filtert Demografie-/Placement-Breakdown-Zeilen heraus, die sonst denselben
     * Spend mehrfach zählen würden. Ohne aktuellen Tenant oder ohne passenden
     * Snapshot bleiben die Kosten-Felder `null` (nicht `0.0`).
     *
     * @return list<array{
     *     source: string, leads: int, won: int, conversion: float, avg_value: float,
     *     cost_per_lead: ?float, cost_per_acquisition: ?float
     * }>
     */
    public function sourceEconomics(Builder $leads, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $rows = (clone $leads)
            ->join('lead_sources', 'leads.' . Lead::fkColumn('lead_source'), '=', 'lead_sources.' . Lead::pkColumn())
            ->selectRaw('lead_sources.name as source')
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN leads.status = ? THEN 1 ELSE 0 END) as won', [LeadStatusEnum::Won->value])
            ->selectRaw('AVG(CASE WHEN leads.value > 0 THEN leads.value END) as avg_value')
            ->groupBy('lead_sources.name')
            ->orderByDesc('leads')
            ->get();

        $campaignIdsBySource = $this->campaignIdsBySource($leads);
        $allCampaignIds      = array_values(array_unique(array_merge([], ...array_values($campaignIdsBySource))));
        $spendByCampaign     = $this->adSpendByCampaign($allCampaignIds, $from, $to);

        return $rows
            ->map(function ($row) use ($campaignIdsBySource, $spendByCampaign): array {
                $leadsCount = (int) $row->leads;
                $won        = (int) $row->won;

                $spend = array_sum(array_map(
                    fn (string $campaignId): float => $spendByCampaign[$campaignId] ?? 0.0,
                    $campaignIdsBySource[$row->source] ?? [],
                ));

                return [
                    'source'               => (string) $row->source,
                    'leads'                => $leadsCount,
                    'won'                  => $won,
                    'conversion'           => $leadsCount > 0 ? round($won / $leadsCount * 100, 1) : 0.0,
                    'avg_value'            => round((float) ($row->avg_value ?? 0), 2),
                    'cost_per_lead'        => ($spend > 0 && $leadsCount > 0) ? round($spend / $leadsCount, 2) : null,
                    'cost_per_acquisition' => ($spend > 0 && $won > 0) ? round($spend / $won, 2) : null,
                ];
            })
            ->all();
    }

    /**
     * Lead-Ops-Ranking je zugewiesenem Berater: gewichteter Composite aus
     * SLA-Erfüllung (40 %), Gewinnrate (40 %) und Reaktions-Score (20 %),
     * absteigend nach `ops_score` sortiert.
     *
     * @return list<array{
     *     advisor_id: ?string, avg_response_minutes: ?float, sla_pct: float,
     *     contact_attempts: int, won: int, ops_score: float
     * }>
     */
    public function advisorOps(Builder $leads, CarbonImmutable $from, CarbonImmutable $to, int $slaMinutes = 60): array
    {
        $advisorIds = (clone $leads)->whereNotNull('assigned_to')->distinct()->pluck('assigned_to');

        // int|string: pluck('assigned_to') yields int under 'id' primary-key mode,
        // string under 'uuid' — strict_types would TypeError on a string-only hint.
        $rows = $advisorIds->map(function (int|string $advisorId) use ($leads, $from, $to, $slaMinutes): array {
            $scoped   = (clone $leads)->where('assigned_to', $advisorId);
            $response = $this->responseStats((clone $scoped), $from, $to, $slaMinutes);

            $won     = (clone $scoped)->where('status', LeadStatusEnum::Won)->count();
            $lost    = (clone $scoped)->where('status', LeadStatusEnum::Lost)->count();
            $winRate = ($won + $lost) > 0 ? $won / ($won + $lost) : 0.0;

            $attempts = LeadActivity::query()
                ->whereIn(Lead::fkColumn('lead'), (clone $scoped)->pluck(Lead::pkColumn()))
                ->whereIn('type', [LeadActivityTypeEnum::Call->value, LeadActivityTypeEnum::Email->value])
                ->count();

            $responseScore = null === $response['avg_minutes']
                ? 0.0
                : max(0.0, 1 - min($response['avg_minutes'] / 1440, 1.0)); // 0 min → 1, ≥24h → 0

            $opsScore = round(
                ($response['sla_pct'] / 100 * 0.4 + $winRate * 0.4 + $responseScore * 0.2) * 100,
                1,
            );

            return [
                'advisor_id'           => (string) $advisorId,
                'avg_response_minutes' => $response['avg_minutes'],
                'sla_pct'              => $response['sla_pct'],
                'contact_attempts'     => $attempts,
                'won'                  => $won,
                'ops_score'            => $opsScore,
            ];
        })->values()->all();

        usort($rows, fn (array $a, array $b): int => $b['ops_score'] <=> $a['ops_score']);

        return $rows;
    }

    /**
     * Distinct, nicht-null `source_campaign_id`s je Lead-Quelle, ermittelt aus
     * dem übergebenen (bereits gescopeten) Leads-Builder.
     *
     * @return array<string, list<string>> lead_sources.name => Kampagnen-IDs
     */
    private function campaignIdsBySource(Builder $leads): array
    {
        return (clone $leads)
            ->join('lead_sources', 'leads.' . Lead::fkColumn('lead_source'), '=', 'lead_sources.' . Lead::pkColumn())
            ->whereNotNull('leads.source_campaign_id')
            ->select('lead_sources.name as source', 'leads.source_campaign_id')
            ->distinct()
            ->get()
            ->groupBy('source')
            ->map(fn ($group) => $group->pluck('source_campaign_id')->all())
            ->all();
    }

    /**
     * Summierter Meta-Ad-Spend je Kampagne für den aktuellen Tenant, ohne
     * Breakdown-Zeilen (`breakdown_type = 'none'`), optional auf einen Zeitraum
     * eingeschränkt. Ohne aktuellen Tenant oder ohne Kampagnen-IDs wird gar
     * nicht erst gegen die DB gefragt.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, float> campaign_id => Spend-Summe
     */
    private function adSpendByCampaign(array $campaignIds, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $tenantId = function_exists('filament') ? filament()->getTenant()?->getKey() : null;

        if (null === $tenantId || [] === $campaignIds) {
            return [];
        }

        $query = MetaInsightSnapshot::query()
            ->where(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'), $tenantId)
            ->where('breakdown_type', 'none')
            ->whereIn('campaign_id', $campaignIds);

        // Apply each bound independently — a partial range (only $from or only
        // $to) must still filter, not silently sum all-time spend.
        if (null !== $from) {
            $query->where('date', '>=', $from->toDateString());
        }
        if (null !== $to) {
            $query->where('date', '<=', $to->toDateString());
        }

        return $query
            ->selectRaw('campaign_id, SUM(spend) as total_spend')
            ->groupBy('campaign_id')
            ->pluck('total_spend', 'campaign_id')
            ->map(fn (mixed $spend): float => (float) $spend)
            ->all();
    }
}
