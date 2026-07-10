<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
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
    public function responseStats(Builder $leads, ?CarbonImmutable $from, ?CarbonImmutable $to, ?int $slaMinutes = null): array
    {
        $slaMinutes ??= (int) config('lead-pipeline.operations.sla_minutes', 60);
        $rows = (clone $leads)
            ->when($from, fn (Builder $q): Builder => $q->where('leads.created_at', '>=', $from))
            ->when($to, fn (Builder $q): Builder => $q->where('leads.created_at', '<=', $to))
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
     * Gefenstert wird auf die Moves selbst: ein Dwell-Paar zählt nur, wenn BEIDE
     * Moves im Fenster liegen. Paare, die die Fenstergrenze überspannen, werden
     * bewusst verworfen (kein Clipping) — die Kennzahl beschreibt Bewegungen,
     * die vollständig im Zeitraum stattfanden.
     *
     * @return list<array{phase_id: string, label: string, avg_days: float, overaged_pct: float}>
     */
    public function stageDwell(Builder $leads, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $leadIds = (clone $leads)->pluck(Lead::pkColumn());

        $moves = LeadActivity::query()
            ->whereIn(Lead::fkColumn('lead'), $leadIds)
            ->where('type', LeadActivityTypeEnum::Moved->value)
            ->when($from, fn (Builder $q): Builder => $q->where('created_at', '>=', $from))
            ->when($to, fn (Builder $q): Builder => $q->where('created_at', '<=', $to))
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
     * werden ausgeschlossen, da sie keine auswertbare Information liefern. Bei
     * gesetzten Bounds werden nur Leads berücksichtigt, die IM Fenster per
     * Moved-Activity in eine Lost-Typ-Phase geschoben wurden.
     *
     * @return list<array{reason: string, count: int}>
     */
    public function lossReasons(Builder $leads, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $query = (clone $leads)
            ->whereNotNull('lost_reason')
            ->where('lost_reason', '!=', '');

        if (null !== $from || null !== $to) {
            $lostPhaseIds = $this->phaseIdsOfType($leads, LeadPhaseTypeEnum::Lost);
            $query->whereHas('activities', function (Builder $q) use ($lostPhaseIds, $from, $to): void {
                $q->where('type', LeadActivityTypeEnum::Moved->value)
                    ->whereIn('properties->new_phase', $lostPhaseIds)
                    ->when($from, fn (Builder $qq): Builder => $qq->where('created_at', '>=', $from))
                    ->when($to, fn (Builder $qq): Builder => $qq->where('created_at', '<=', $to));
            });
        }

        return $query
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
    public function contactHeatmap(Builder $leads, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $slots  = ['8–10', '10–12', '12–14', '14–16', '16–18', '18–20'];
        $days   = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $matrix = array_fill(0, 6, array_fill(0, 6, 0));

        $leadIds    = (clone $leads)->pluck(Lead::pkColumn());
        $activities = LeadActivity::query()
            ->whereIn(Lead::fkColumn('lead'), $leadIds)
            ->whereIn('type', [LeadActivityTypeEnum::Call->value, LeadActivityTypeEnum::Email->value])
            ->when($from, fn (Builder $q): Builder => $q->where('created_at', '>=', $from))
            ->when($to, fn (Builder $q): Builder => $q->where('created_at', '<=', $to))
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
    public function advisorOps(Builder $leads, ?CarbonImmutable $from, ?CarbonImmutable $to, ?int $slaMinutes = null): array
    {
        $slaMinutes ??= (int) config('lead-pipeline.operations.sla_minutes', 60);
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
     * Aktivitäts- und Ergebnis-Matrix je Berater im gewählten Zeitraum.
     *
     * Semantik: Aktivitäts-Spalten (`calls`/`emails`/`notes`/`moves`) zählen
     * nach `causer_id` (wer hat es getan); `first_contacts` = Leads mit
     * `first_response_by` = Berater und `first_response_at` im Fenster;
     * `assigned_new` = `assignment`-Activities mit `properties->assigned_to`
     * = Berater im Fenster; `won`/`lost` = Moved-Activities in Won-/Lost-Typ-
     * Phasen im Fenster, gezählt nach `leads.assigned_to` (Ergebnis-
     * verantwortung, nicht Causer); `conversion` = won/(won+lost)*100;
     * `avg_response_minutes`/`sla_pct` via `responseStats()` je Berater (auf
     * `assigned_to` gescoped); `activities_per_lead` = (calls+emails+notes+
     * moves) / aktuell zugewiesene Leads (null wenn 0 zugewiesen).
     *
     * Berater-Menge = Union aus Activity-Causern im Fenster, aktuell
     * zugewiesenen Beratern der gescopten Leads, Erstkontaktern
     * (`first_response_by`) und Assignment-Empfängern im Fenster (wer nichts
     * tat, erscheint mit Nullen — genau das will das Management sehen).
     *
     * Team-Aggregat: `sla_pct` mittelt nur über Berater mit mindestens einer
     * Antwort im Fenster (`avg_response_minutes` ≠ null) — Nicht-Responder
     * verwässern den Team-SLA nicht.
     *
     * Score v2 (`scores`, siehe `attachScores()`) und Deltas: `delta_score`/
     * `delta_won` vergleichen mit dem gleich langen Vorfenster (nur wenn
     * `$from` UND `$to` gesetzt sind — sonst `null`). Das Vorfenster wird
     * selbst OHNE weitere Deltas berechnet (`withDeltas: false`), um die
     * Rekursion auf eine Ebene zu begrenzen. Rows werden danach nach
     * `scores.total` absteigend sortiert; `team.score_avg` ist der
     * ungewichtete Durchschnitt der Row-Totals.
     *
     * @return array{
     *   rows: list<array{
     *     advisor_id: string, advisor_name: string,
     *     calls: int, emails: int, notes: int, moves: int,
     *     first_contacts: int, assigned_new: int, won: int, lost: int,
     *     conversion: float, avg_response_minutes: ?float, sla_pct: float,
     *     activities_per_lead: ?float,
     *     scores: array{activity: float, tempo: float, result: float, diligence: float, total: float},
     *     delta_score: ?float, delta_won: ?int
     *   }>,
     *   team: array{calls: int, emails: int, notes: int, moves: int, first_contacts: int,
     *     assigned_new: int, won: int, lost: int, conversion: float,
     *     avg_response_minutes: ?float, sla_pct: float, activities_per_lead: ?float,
     *     score_avg: float}
     * }
     */
    public function advisorActivityMatrix(Builder $leads, ?CarbonImmutable $from, ?CarbonImmutable $to, bool $withDeltas = true): array
    {
        $leadFk  = Lead::fkColumn('lead');
        $leadIds = (clone $leads)->pluck('leads.' . Lead::pkColumn());

        // Qualified with the table name: outcomeCounts() below joins `leads`
        // (which also has a created_at column), so a bare "created_at" is
        // ambiguous once that join is applied — qualifying it here keeps this
        // closure safe to reuse across joined and unjoined lead_activities queries.
        $activityWindow = fn (Builder $q): Builder => $q
            ->when($from, fn (Builder $qq): Builder => $qq->where('lead_activities.created_at', '>=', $from))
            ->when($to, fn (Builder $qq): Builder => $qq->where('lead_activities.created_at', '<=', $to));

        // 1) Aktivitäts-Zählungen je Causer × Typ (eine Query).
        $countable = [
            LeadActivityTypeEnum::Call->value,
            LeadActivityTypeEnum::Email->value,
            LeadActivityTypeEnum::Note->value,
            LeadActivityTypeEnum::Moved->value,
        ];
        $activityCounts = LeadActivity::query()
            ->whereIn($leadFk, $leadIds)
            ->whereNotNull('causer_id')
            ->whereIn('type', $countable)
            ->tap($activityWindow)
            ->selectRaw('causer_id, type, COUNT(*) as cnt')
            ->groupBy('causer_id', 'type')
            ->get()
            ->groupBy(fn ($row): string => (string) $row->causer_id);

        // 2) Won/Lost im Fenster nach Ergebnisverantwortung (leads.assigned_to).
        $wonPhaseIds  = $this->phaseIdsOfType($leads, LeadPhaseTypeEnum::Won);
        $lostPhaseIds = $this->phaseIdsOfType($leads, LeadPhaseTypeEnum::Lost);

        $outcomeCounts = fn (array $phaseIds) => LeadActivity::query()
            ->whereIn('lead_activities.' . $leadFk, $leadIds)
            ->where('type', LeadActivityTypeEnum::Moved->value)
            ->whereIn('properties->new_phase', $phaseIds)
            ->tap($activityWindow)
            ->join('leads', 'leads.' . Lead::pkColumn(), '=', 'lead_activities.' . $leadFk)
            ->whereNotNull('leads.assigned_to')
            ->selectRaw('leads.assigned_to as advisor, COUNT(*) as cnt')
            ->groupBy('leads.assigned_to')
            ->pluck('cnt', 'advisor');
        $wonByAdvisor  = $outcomeCounts($wonPhaseIds);
        $lostByAdvisor = $outcomeCounts($lostPhaseIds);

        // 3) Erstkontakte & Neuzuweisungen.
        $firstContacts = (clone $leads)
            ->whereNotNull('first_response_by')
            ->when($from, fn (Builder $q): Builder => $q->where('first_response_at', '>=', $from))
            ->when($to, fn (Builder $q): Builder => $q->where('first_response_at', '<=', $to))
            ->reorder()
            ->selectRaw('first_response_by as advisor, COUNT(*) as cnt')
            ->groupBy('first_response_by')
            ->pluck('cnt', 'advisor');

        // json_unquote() doesn't exist on SQLite; json_extract() already returns
        // the unquoted scalar there, so the extraction expression is driver-branched.
        $jsonAdvisor = 'sqlite' === LeadActivity::query()->getConnection()->getDriverName()
            ? "json_extract(properties, '$.assigned_to')"
            : "json_unquote(json_extract(properties, '$.assigned_to'))";

        $assignedNew = LeadActivity::query()
            ->whereIn($leadFk, $leadIds)
            ->where('type', LeadActivityTypeEnum::Assignment->value)
            ->whereNotNull('properties->assigned_to')
            ->tap($activityWindow)
            ->selectRaw("{$jsonAdvisor} as advisor, COUNT(*) as cnt")
            ->groupBy('advisor')
            ->pluck('cnt', 'advisor');

        // 4) Berater-Menge: Causer ∪ aktuell Zugewiesene ∪ Erstkontakter ∪
        //    Assignment-Empfänger — wer nur via first_response_by oder
        //    properties->assigned_to im Fenster auftaucht, bekommt trotzdem
        //    eine Zeile. Namen auflösen.
        $assignedCounts = (clone $leads)
            ->whereNotNull('leads.assigned_to')
            ->reorder()
            ->selectRaw('leads.assigned_to as advisor, COUNT(*) as cnt')
            ->groupBy('leads.assigned_to')
            ->pluck('cnt', 'advisor');

        $advisorIds = collect($activityCounts->keys())
            ->merge($assignedCounts->keys()->map(fn ($k): string => (string) $k))
            ->merge($firstContacts->keys()->map(fn ($k): string => (string) $k))
            ->merge($assignedNew->keys()->map(fn ($k): string => (string) $k))
            ->unique()
            ->values();

        $userModel = config('lead-pipeline.user_model');
        $names     = $userModel::query()
            ->whereIn((new $userModel())->getKeyName(), $advisorIds)
            ->get()
            ->keyBy(fn ($u): string => (string) $u->getKey());

        $rows = $advisorIds->map(function (string $id) use (
            $activityCounts,
            $wonByAdvisor,
            $lostByAdvisor,
            $firstContacts,
            $assignedNew,
            $assignedCounts,
            $names,
            $leads,
            $from,
            $to,
        ): array {
            $byType = ($activityCounts->get($id) ?? collect())->keyBy('type');
            $count  = fn (LeadActivityTypeEnum $t): int => (int) ($byType[$t->value]->cnt ?? 0);

            $won  = (int) ($wonByAdvisor[$id] ?? 0);
            $lost = (int) ($lostByAdvisor[$id] ?? 0);

            $response = $this->responseStats(
                (clone $leads)->where('leads.assigned_to', $id),
                $from,
                $to,
            );

            $assigned      = (int) ($assignedCounts[$id] ?? 0);
            $activityTotal = $count(LeadActivityTypeEnum::Call) + $count(LeadActivityTypeEnum::Email)
                + $count(LeadActivityTypeEnum::Note) + $count(LeadActivityTypeEnum::Moved);

            return [
                'advisor_id'           => $id,
                'advisor_name'         => (string) ($names[$id]->name ?? __('lead-pipeline::lead-pipeline.field.unknown')),
                'calls'                => $count(LeadActivityTypeEnum::Call),
                'emails'               => $count(LeadActivityTypeEnum::Email),
                'notes'                => $count(LeadActivityTypeEnum::Note),
                'moves'                => $count(LeadActivityTypeEnum::Moved),
                'first_contacts'       => (int) ($firstContacts[$id] ?? 0),
                'assigned_new'         => (int) ($assignedNew[$id] ?? 0),
                'won'                  => $won,
                'lost'                 => $lost,
                'conversion'           => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0.0,
                'avg_response_minutes' => $response['avg_minutes'],
                'sla_pct'              => $response['sla_pct'],
                'activities_per_lead'  => $assigned > 0 ? round($activityTotal / $assigned, 2) : null,
            ];
        })->values()->all();

        $rows = $this->attachScores($rows, $leads);

        if ($withDeltas && null !== $from && null !== $to) {
            $spanSeconds = $to->getTimestamp() - $from->getTimestamp();
            $previous    = $this->advisorActivityMatrix(
                clone $leads,
                $from->subSeconds($spanSeconds),
                $from,
                withDeltas: false,
            );
            $prevRows = collect($previous['rows'])->keyBy('advisor_id');

            $rows = array_map(function (array $row) use ($prevRows): array {
                $prev               = $prevRows->get($row['advisor_id']);
                $row['delta_score'] = null === $prev ? null : round($row['scores']['total'] - $prev['scores']['total'], 1);
                $row['delta_won']   = null === $prev ? null : $row['won'] - $prev['won'];

                return $row;
            }, $rows);
        } else {
            $rows = array_map(fn (array $row): array => $row + ['delta_score' => null, 'delta_won' => null], $rows);
        }

        usort($rows, fn (array $a, array $b): int => $b['scores']['total'] <=> $a['scores']['total']);

        $team              = $this->teamAggregate($rows);
        $team['score_avg'] = [] === $rows
            ? 0.0
            : round(array_sum(array_map(fn (array $r): float => $r['scores']['total'], $rows)) / count($rows), 1);

        return ['rows' => $rows, 'team' => $team];
    }

    /**
     * @return array{
     *   row: ?array<string, mixed>,        // Matrix-Row des Beraters (inkl. scores/deltas), null wenn unbekannt
     *   team: array<string, mixed>,        // Team-Aggregate (inkl. score_avg)
     *   rank: ?int, total_advisors: int    // Platz im Score-Ranking (1-basiert)
     * }
     */
    public function advisorScorecard(int|string $advisorId, Builder $leads, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $matrix = $this->advisorActivityMatrix($leads, $from, $to);

        $rank = null;
        $row  = null;
        foreach ($matrix['rows'] as $i => $candidate) {
            if ($candidate['advisor_id'] === (string) $advisorId) {
                $rank = $i + 1;
                $row  = $candidate;
                break;
            }
        }

        return [
            'row'            => $row,
            'team'           => $matrix['team'],
            'rank'           => $rank,
            'total_advisors' => count($matrix['rows']),
        ];
    }

    /**
     * Score v2: vier erklärbare Teilscores (0–100) + gewichtete Summe.
     *
     * - `activity` = `min(100, apl / (2 × teamMedianApl) × 100)`; ist der
     *   Team-Median ≤ 0, gilt `apl > 0 ? 100 : 0`. Der Median wird über alle
     *   Rows mit nicht-null `activities_per_lead` gebildet.
     * - `tempo` = `0.6 × sla_pct + 40 × responseScore`, mit
     *   `responseScore = avg_response_minutes === null ? 0 : max(0, 1 − min(avg/1440, 1))`.
     * - `result` = `0.6 × conversion + 0.4 × min(100, wonShare × 100)`, mit
     *   `wonShare = teamWon > 0 ? won / teamWon : 0`.
     * - `diligence` = `max(0, 100 − 50×overdueRatio − 30×untouchedRatio − 0.2×(100 − nextStepPct))`,
     *   mit Ratios aus einem Snapshot von `operationsStats()`, gescoped auf
     *   den Berater: `overdueRatio = overdue_followups / max(1, aktiveZugewiesene)`,
     *   `untouchedRatio = untouched / max(1, aktiveZugewiesene)`,
     *   `nextStepPct = next_step_rate`.
     * - `total` = gewichtete Summe der vier Teilscores ÷ Gewichtssumme;
     *   Gewichte aus `config('lead-pipeline.operations.score_weights')`.
     *
     * Alle Werte werden auf eine Nachkommastelle gerundet.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function attachScores(array $rows, Builder $leads): array
    {
        $weights   = (array) config('lead-pipeline.operations.score_weights', ['activity' => 30, 'tempo' => 25, 'result' => 30, 'diligence' => 15]);
        $weightSum = max(1, array_sum($weights));

        $aplValues = array_values(array_filter(array_column($rows, 'activities_per_lead'), fn ($v): bool => null !== $v));
        sort($aplValues);
        $median  = [] === $aplValues ? 0.0 : (float) $aplValues[(int) floor((count($aplValues) - 1) / 2)];
        $teamWon = (int) array_sum(array_column($rows, 'won'));

        return array_map(function (array $row) use ($weights, $weightSum, $median, $teamWon, $leads): array {
            $apl      = $row['activities_per_lead'];
            $activity = null === $apl || $apl <= 0
                ? 0.0
                : ($median <= 0 ? 100.0 : min(100.0, round($apl / (2 * $median) * 100, 1)));

            $responseScore = null === $row['avg_response_minutes']
                ? 0.0
                : max(0.0, 1 - min($row['avg_response_minutes'] / 1440, 1.0));
            $tempo = round(0.6 * $row['sla_pct'] + 40 * $responseScore, 1);

            $wonShare = $teamWon > 0 ? $row['won'] / $teamWon : 0.0;
            $result   = round(0.6 * $row['conversion'] + 0.4 * min(100.0, $wonShare * 100), 1);

            $ops            = $this->operationsStats((clone $leads)->where('leads.assigned_to', $row['advisor_id']));
            $activeAssigned = max(1, (clone $leads)->where('leads.assigned_to', $row['advisor_id'])->where('status', LeadStatusEnum::Active)->count());
            $diligence      = round(max(
                0.0,
                100
                - 50 * ($ops['overdue_followups'] / $activeAssigned)
                - 30 * ($ops['untouched'] / $activeAssigned)
                - 0.2 * (100 - $ops['next_step_rate']),
            ), 1);

            $total = round(
                ($activity * $weights['activity'] + $tempo * $weights['tempo']
                    + $result * $weights['result'] + $diligence * $weights['diligence']) / $weightSum,
                1,
            );

            $row['scores'] = [
                'activity'  => $activity,
                'tempo'     => $tempo,
                'result'    => $result,
                'diligence' => $diligence,
                'total'     => $total,
            ];

            return $row;
        }, $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function teamAggregate(array $rows): array
    {
        $sum  = fn (string $k): int => (int) array_sum(array_column($rows, $k));
        $won  = $sum('won');
        $lost = $sum('lost');

        $responseValues = array_values(array_filter(array_column($rows, 'avg_response_minutes'), fn ($v): bool => null !== $v));
        $aplValues      = array_values(array_filter(array_column($rows, 'activities_per_lead'), fn ($v): bool => null !== $v));

        // sla_pct only over advisors who actually responded in the window
        // (avg_response_minutes !== null): responseStats() reports 0.0 — not
        // null — for zero responses, so averaging over all rows would let
        // inactive advisors dilute the team SLA, inconsistent with the
        // null-filtered avg_response_minutes/activities_per_lead aggregates.
        $slaValues = array_column(
            array_filter($rows, fn (array $row): bool => null !== $row['avg_response_minutes']),
            'sla_pct',
        );

        return [
            'calls'                => $sum('calls'),
            'emails'               => $sum('emails'),
            'notes'                => $sum('notes'),
            'moves'                => $sum('moves'),
            'first_contacts'       => $sum('first_contacts'),
            'assigned_new'         => $sum('assigned_new'),
            'won'                  => $won,
            'lost'                 => $lost,
            'conversion'           => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0.0,
            'avg_response_minutes' => [] === $responseValues ? null : round(array_sum($responseValues) / count($responseValues), 1),
            'sla_pct'              => [] === $slaValues ? 0.0 : round(array_sum($slaValues) / count($slaValues), 1),
            'activities_per_lead'  => [] === $aplValues ? null : round(array_sum($aplValues) / count($aplValues), 2),
        ];
    }

    /** @return list<int|string> Phase-PKs des Typs auf den Boards der gescopten Leads */
    private function phaseIdsOfType(Builder $leads, LeadPhaseTypeEnum $type): array
    {
        $boardIds = (clone $leads)
            ->reorder()
            ->select('leads.' . Lead::fkColumn('lead_board'))
            ->distinct()
            ->pluck(Lead::fkColumn('lead_board'));

        return LeadPhase::query()
            ->whereIn(Lead::fkColumn('lead_board'), $boardIds)
            ->where('type', $type->value)
            ->pluck(LeadPhase::pkColumn())
            ->all();
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

        // Ad-cost enrichment is optional per tenant/deployment: an installation
        // that never set up Meta insights sync has no meta_insight_snapshots
        // table. Skip cost attribution silently rather than 500 the whole
        // operations page (mirrors SyncMetaInsightsJob's Schema::hasTable guard).
        if ( ! Schema::hasTable((new MetaInsightSnapshot())->getTable())) {
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
