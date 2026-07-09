<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;

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
}
