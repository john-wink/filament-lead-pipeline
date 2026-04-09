<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $boardId  = $request->query('boardId');
        $preset   = $request->query('preset', '30');
        $dateFrom = $request->query('dateFrom');
        $dateTo   = $request->query('dateTo');
        $section  = $request->query('section', 'all');

        [$from, $to] = $this->getDateRange($preset, $dateFrom, $dateTo);

        $suffix = $boardId ? 'board' : 'gesamt';

        $sectionName = match ($section) {
            'berater' => 'berater',
            'matrix'  => 'berater-phasen',
            'sources' => 'quellen',
            default   => $suffix,
        };
        $filename = "lead-auswertung-{$sectionName}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($boardId, $from, $to, $section): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

            $query   = $this->baseQuery($boardId, $from, $to);
            $wonIds  = $this->getPhaseIdsByType($boardId, LeadPhaseTypeEnum::Won);
            $lostIds = $this->getPhaseIdsByType($boardId, LeadPhaseTypeEnum::Lost);

            // KPIs (only in 'all')
            if ('all' === $section) {
                fputcsv($handle, ['=== KPIs ==='], ';');
                $total = (clone $query)->count();
                $won   = (clone $query)->whereIn(Lead::fkColumn('lead_phase'), $wonIds)->count();
                $lost  = (clone $query)->whereIn(Lead::fkColumn('lead_phase'), $lostIds)->count();
                $conv  = ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0;
                $avg   = (clone $query)->whereNotNull('value')->where('value', '>', 0)->avg('value') ?? 0;

                fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.kpi_total'), $total], ';');
                fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.kpi_won'), $won], ';');
                fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.kpi_lost'), $lost], ';');
                fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.kpi_conversion'), $conv . '%'], ';');
                fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.kpi_avg_value'), number_format((float) $avg, 2, ',', '.')], ';');
                fputcsv($handle, [], ';');
            }

            // Matrix (in 'all' and 'matrix')
            if (in_array($section, ['all', 'matrix'], true)) {
                $boards = $boardId
                    ? collect([LeadBoard::find($boardId)])
                    : LeadBoard::where(config('lead-pipeline.tenancy.foreign_key'), filament()->getTenant()?->getKey())->get();

                foreach ($boards as $board) {
                    fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.analytics.matrix_title') . ": {$board->name} ==="], ';');
                    $phases = $board->phases()->ordered()->get();

                    $header = [__('lead-pipeline::lead-pipeline.analytics.matrix_advisor'), ...$phases->pluck('name')->toArray(), __('lead-pipeline::lead-pipeline.analytics.matrix_total')];
                    fputcsv($handle, $header, ';');

                    $matrixQuery = Lead::query()->where(Lead::fkColumn('lead_board'), $board->getKey());
                    $user        = auth()->user();
                    if ($user && $board->isAdmin($user)) {
                        // see all
                    } elseif ($user) {
                        $matrixQuery->where('assigned_to', $user->getKey());
                    }
                    if ($from) {
                        $matrixQuery->where('leads.created_at', '>=', $from);
                    }
                    if ($to) {
                        $matrixQuery->where('leads.created_at', '<=', $to->endOfDay());
                    }

                    $rows = $matrixQuery->select('assigned_to', Lead::fkColumn('lead_phase'), DB::raw('COUNT(*) as cnt'))
                        ->groupBy('assigned_to', Lead::fkColumn('lead_phase'))->get();

                    $beraterIds = $rows->pluck('assigned_to')->filter()->unique();
                    $userModel  = config('lead-pipeline.user_model');
                    $berater    = $beraterIds->isNotEmpty()
                        ? $userModel::whereIn($userModel::query()->getModel()->getKeyName(), $beraterIds)->get()->keyBy(fn ($u) => $u->getKey())
                        : collect();

                    $totals     = array_fill(0, $phases->count(), 0);
                    $grandTotal = 0;

                    foreach ($rows->groupBy('assigned_to') as $assignedTo => $group) {
                        $name = ('' === $assignedTo || null === $assignedTo)
                            ? __('lead-pipeline::lead-pipeline.field.not_assigned')
                            : ($berater[$assignedTo]?->name ?? __('lead-pipeline::lead-pipeline.field.unknown'));

                        $row      = [$name];
                        $rowTotal = 0;
                        foreach ($phases->values() as $i => $phase) {
                            $count = (int) $group->where(Lead::fkColumn('lead_phase'), $phase->getKey())->sum('cnt');
                            $row[] = $count;
                            $totals[$i] += $count;
                            $rowTotal += $count;
                        }
                        $row[] = $rowTotal;
                        $grandTotal += $rowTotal;
                        fputcsv($handle, $row, ';');
                    }

                    fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.matrix_total'), ...$totals, $grandTotal], ';');
                    fputcsv($handle, [], ';');
                }
            } // end matrix

            // Berater Summary (in 'all' and 'berater')
            if (in_array($section, ['all', 'berater'], true)) {
                $beraterBoards = $boardId
                    ? collect([LeadBoard::find($boardId)])
                    : LeadBoard::where(config('lead-pipeline.tenancy.foreign_key'), filament()->getTenant()?->getKey())->get();

                foreach ($beraterBoards as $board) {
                    $phases = $board->phases()->ordered()->get();

                    if (count($beraterBoards) > 1) {
                        fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.analytics.export_berater') . ": {$board->name} ==="], ';');
                    } else {
                        fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.analytics.export_berater') . ' ==='], ';');
                    }

                    $header = [__('lead-pipeline::lead-pipeline.analytics.matrix_advisor'), ...$phases->pluck('name')->toArray(), __('lead-pipeline::lead-pipeline.analytics.matrix_total'), __('lead-pipeline::lead-pipeline.analytics.kpi_won'), __('lead-pipeline::lead-pipeline.analytics.kpi_lost'), __('lead-pipeline::lead-pipeline.analytics.sources_conversion'), __('lead-pipeline::lead-pipeline.analytics.kpi_avg_value')];
                    fputcsv($handle, $header, ';');

                    $beraterQuery = Lead::query()->where(Lead::fkColumn('lead_board'), $board->getKey());
                    $user         = auth()->user();
                    if ($user && $board->isAdmin($user)) {
                        // see all
                    } elseif ($user) {
                        $beraterQuery->where('assigned_to', $user->getKey());
                    }
                    if ($from) {
                        $beraterQuery->where('leads.created_at', '>=', $from);
                    }
                    if ($to) {
                        $beraterQuery->where('leads.created_at', '<=', $to->endOfDay());
                    }

                    $boardWonIds  = $phases->where('type', LeadPhaseTypeEnum::Won)->pluck(LeadPhase::pkColumn())->toArray();
                    $boardLostIds = $phases->where('type', LeadPhaseTypeEnum::Lost)->pluck(LeadPhase::pkColumn())->toArray();

                    $rows = (clone $beraterQuery)
                        ->select('assigned_to', Lead::fkColumn('lead_phase'), DB::raw('COUNT(*) as cnt'))
                        ->selectRaw('SUM(CASE WHEN leads.value > 0 THEN leads.value ELSE 0 END) as sum_value')
                        ->selectRaw('COUNT(CASE WHEN leads.value > 0 THEN 1 END) as value_count')
                        ->groupBy('assigned_to', Lead::fkColumn('lead_phase'))
                        ->get();

                    $beraterIds = $rows->pluck('assigned_to')->filter()->unique();
                    $userModel  = config('lead-pipeline.user_model');
                    $berater    = $beraterIds->isNotEmpty()
                        ? $userModel::whereIn($userModel::query()->getModel()->getKeyName(), $beraterIds)->get()->keyBy(fn ($u) => $u->getKey())
                        : collect();

                    foreach ($rows->groupBy('assigned_to') as $assignedTo => $group) {
                        $name = ('' === $assignedTo || null === $assignedTo)
                            ? __('lead-pipeline::lead-pipeline.field.not_assigned')
                            : ($berater[$assignedTo]?->name ?? __('lead-pipeline::lead-pipeline.field.unknown'));

                        $csvRow  = [$name];
                        $total   = 0;
                        $wonCnt  = 0;
                        $lostCnt = 0;
                        $sumVal  = 0;
                        $valCnt  = 0;

                        foreach ($phases as $phase) {
                            $phaseRow = $group->where(Lead::fkColumn('lead_phase'), $phase->getKey())->first();
                            $count    = $phaseRow ? (int) $phaseRow->cnt : 0;
                            $csvRow[] = $count;
                            $total += $count;

                            if (in_array($phase->getKey(), $boardWonIds, true)) {
                                $wonCnt += $count;
                            }
                            if (in_array($phase->getKey(), $boardLostIds, true)) {
                                $lostCnt += $count;
                            }
                            if ($phaseRow) {
                                $sumVal += (float) $phaseRow->sum_value;
                                $valCnt += (int) $phaseRow->value_count;
                            }
                        }

                        $conv   = ($wonCnt + $lostCnt) > 0 ? round($wonCnt / ($wonCnt + $lostCnt) * 100, 1) : 0;
                        $avgVal = $valCnt > 0 ? round($sumVal / $valCnt, 2) : 0;

                        $csvRow[] = $total;
                        $csvRow[] = $wonCnt;
                        $csvRow[] = $lostCnt;
                        $csvRow[] = $conv . '%';
                        $csvRow[] = number_format($avgVal, 2, ',', '.');

                        fputcsv($handle, $csvRow, ';');
                    }
                    fputcsv($handle, [], ';');
                }
            }

            // Sources (in 'all' and 'sources')
            if (in_array($section, ['all', 'sources'], true)) {
                fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.analytics.sources_title') . ' ==='], ';');
                fputcsv($handle, [__('lead-pipeline::lead-pipeline.analytics.sources_source'), __('lead-pipeline::lead-pipeline.analytics.sources_leads'), __('lead-pipeline::lead-pipeline.analytics.kpi_won'), __('lead-pipeline::lead-pipeline.analytics.kpi_lost'), __('lead-pipeline::lead-pipeline.analytics.sources_conversion'), __('lead-pipeline::lead-pipeline.analytics.kpi_avg_value')], ';');

                $wonIn      = $this->inPlaceholders($wonIds);
                $lostIn     = $this->inPlaceholders($lostIds);
                $sourceRows = (clone $query)
                    ->join('lead_sources', 'leads.' . Lead::fkColumn('lead_source'), '=', 'lead_sources.' . Lead::pkColumn())
                    ->select('lead_sources.name as source_name')
                    ->selectRaw('COUNT(*) as total')
                    ->selectRaw('SUM(CASE WHEN ' . Lead::fkColumn('lead_phase') . ' IN (' . $wonIn['sql'] . ') THEN 1 ELSE 0 END) as won', $wonIn['bindings'])
                    ->selectRaw('SUM(CASE WHEN ' . Lead::fkColumn('lead_phase') . ' IN (' . $lostIn['sql'] . ') THEN 1 ELSE 0 END) as lost', $lostIn['bindings'])
                    ->selectRaw('AVG(CASE WHEN leads.value > 0 THEN leads.value ELSE NULL END) as avg_value')
                    ->groupBy('lead_sources.name')
                    ->orderByDesc('total')
                    ->get();

                foreach ($sourceRows as $row) {
                    $conv = ($row->won + $row->lost) > 0 ? round($row->won / ($row->won + $row->lost) * 100, 1) : 0;
                    fputcsv($handle, [
                        $row->source_name,
                        $row->total,
                        $row->won,
                        $row->lost,
                        $conv . '%',
                        number_format((float) ($row->avg_value ?? 0), 2, ',', '.'),
                    ], ';');
                }
            } // end sources

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function baseQuery(?string $boardId, ?Carbon $from, ?Carbon $to): \Illuminate\Database\Eloquent\Builder
    {
        $query = Lead::query();
        $user  = auth()->user();

        if ($boardId) {
            $board = LeadBoard::find($boardId);

            // Verify board belongs to current tenant
            if ($board && config('lead-pipeline.tenancy.enabled')) {
                $tenantFk = config('lead-pipeline.tenancy.foreign_key');
                $tenantId = filament()->getTenant()?->getKey();
                if ($board->{$tenantFk} !== $tenantId) {
                    abort(403);
                }
            }

            $query->where(Lead::fkColumn('lead_board'), $boardId);

            if ($board && $user) {
                $query->visibleTo($user, $board);
            }
        } else {
            $tenantFk = config('lead-pipeline.tenancy.foreign_key');
            $tenantId = filament()->getTenant()?->getKey();
            if ($tenantId && $user) {
                // Admins: only boards where user is admin
                $adminBoardIds = LeadBoard::where($tenantFk, $tenantId)
                    ->whereHas('admins', fn ($q) => $q->where('lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'), $user->getKey()))
                    ->pluck(LeadBoard::pkColumn());

                if ($adminBoardIds->isEmpty()) {
                    // Non-admin: only own leads across all team boards
                    $allBoardIds = LeadBoard::where($tenantFk, $tenantId)->pluck(LeadBoard::pkColumn());
                    $query->whereIn(Lead::fkColumn('lead_board'), $allBoardIds)
                        ->where('leads.assigned_to', $user->getKey());
                } else {
                    $query->whereIn(Lead::fkColumn('lead_board'), $adminBoardIds);
                }
            }
        }

        if ($from) {
            $query->where('leads.created_at', '>=', $from);
        }
        if ($to) {
            $query->where('leads.created_at', '<=', $to->endOfDay());
        }

        return $query;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    protected function getDateRange(string $preset, ?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom || $dateTo) {
            return [
                $dateFrom ? Carbon::parse($dateFrom) : null,
                $dateTo ? Carbon::parse($dateTo) : null,
            ];
        }

        return match ($preset) {
            'today' => [now()->startOfDay(), now()],
            '7'     => [now()->subDays(7), now()],
            '30'    => [now()->subDays(30), now()],
            '90'    => [now()->subDays(90), now()],
            default => [null, null],
        };
    }

    protected function getPhaseIdsByType(?string $boardId, LeadPhaseTypeEnum $type): array
    {
        if ($boardId) {
            return LeadPhase::where(LeadPhase::fkColumn('lead_board'), $boardId)
                ->where('type', $type)->pluck(LeadPhase::pkColumn())->toArray();
        }

        $tenantFk = config('lead-pipeline.tenancy.foreign_key');
        $tenantId = filament()->getTenant()?->getKey();
        $boardIds = LeadBoard::where($tenantFk, $tenantId)->pluck(LeadBoard::pkColumn());

        return LeadPhase::whereIn(LeadPhase::fkColumn('lead_board'), $boardIds)
            ->where('type', $type)->pluck(LeadPhase::pkColumn())->toArray();
    }

    /** @return array{sql: string, bindings: array<string>} */
    protected function inPlaceholders(array $ids): array
    {
        if (empty($ids)) {
            return ['sql' => 'NULL', 'bindings' => []];
        }

        return [
            'sql'      => implode(',', array_fill(0, count($ids), '?')),
            'bindings' => array_values($ids),
        ];
    }
}
