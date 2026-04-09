<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\On;
use Livewire\Component;

class LeadAnalyticsModal extends Component
{
    public bool $isOpen = false;

    public ?string $boardId = null;

    public bool $initialized = false;

    public string $preset = '30';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    /** @var array<string, mixed> */
    public array $kpis = [];

    /** @var array<string, mixed> */
    public array $trendData = [];

    /** @var array<string, mixed> */
    public array $matrixData = [];

    /** @var array<string, mixed> */
    public array $sourcesData = [];

    /** @var array<string, mixed> */
    public array $beraterChartData = [];

    /** @var array<string, mixed> */
    public array $sourcesChartData = [];

    #[On('open-analytics')]
    public function open(?string $boardId = null): void
    {
        $this->boardId     = $boardId;
        $this->initialized = false;
        $this->isOpen      = true;
        $this->preset      = '30';
        $this->dateFrom    = null;
        $this->dateTo      = null;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset(['kpis', 'trendData', 'matrixData', 'sourcesData', 'beraterChartData', 'sourcesChartData']);
    }

    public function loadData(): void
    {
        $this->initialized = true;
        $this->computeAll();
    }

    public function updatedPreset(): void
    {
        if ('custom' !== $this->preset) {
            $this->dateFrom = null;
            $this->dateTo   = null;
        }
        if ($this->initialized) {
            $this->computeAll();
        }
    }

    public function updatedDateFrom(): void
    {
        $this->preset = 'custom';
        if ($this->initialized) {
            $this->computeAll();
        }
    }

    public function updatedDateTo(): void
    {
        $this->preset = 'custom';
        if ($this->initialized) {
            $this->computeAll();
        }
    }

    public function getExportUrl(string $section = 'all'): string
    {
        return route('lead-pipeline.analytics.export', [
            'boardId'  => $this->boardId,
            'preset'   => $this->preset,
            'dateFrom' => $this->dateFrom,
            'dateTo'   => $this->dateTo,
            'section'  => $section,
        ]);
    }

    public function render(): View
    {
        return view('lead-pipeline::kanban.analytics-modal');
    }

    protected function computeAll(): void
    {
        $query = $this->baseQuery();

        $this->kpis             = $this->computeKpis($query);
        $this->trendData        = $this->computeTrend($query);
        $this->matrixData       = $this->computeMatrix();
        $this->sourcesData      = $this->computeSources($query);
        $this->beraterChartData = $this->computeBeraterChart();
        $this->sourcesChartData = $this->computeSourcesChart($query);
    }

    protected function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Lead::query();
        $user  = auth()->user();

        if ($this->boardId) {
            $board = LeadBoard::find($this->boardId);

            // Verify board belongs to current tenant
            if ($board && config('lead-pipeline.tenancy.enabled')) {
                $tenantFk = config('lead-pipeline.tenancy.foreign_key');
                $tenantId = filament()->getTenant()?->getKey();
                if ($board->{$tenantFk} !== $tenantId) {
                    abort(403);
                }
            }

            $query->where('leads.' . Lead::fkColumn('lead_board'), $this->boardId);

            // Visibility: admins see all, non-admins see only own leads
            if ($board && $user) {
                $query->visibleTo($user, $board);
            }
        } else {
            // All boards: only include boards where user is admin
            $tenantFk = config('lead-pipeline.tenancy.foreign_key');
            $tenantId = filament()->getTenant()?->getKey();
            if ($tenantId && $user) {
                $adminBoardIds = LeadBoard::where($tenantFk, $tenantId)
                    ->whereHas('admins', fn ($q) => $q->where('lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'), $user->getKey()))
                    ->pluck(LeadBoard::pkColumn());

                if ($adminBoardIds->isEmpty()) {
                    // Not admin on any board — show only own leads across all team boards
                    $allBoardIds = LeadBoard::where($tenantFk, $tenantId)->pluck(LeadBoard::pkColumn());
                    $query->whereIn('leads.' . Lead::fkColumn('lead_board'), $allBoardIds)
                        ->where('leads.assigned_to', $user->getKey());
                } else {
                    $query->whereIn('leads.' . Lead::fkColumn('lead_board'), $adminBoardIds);
                }
            }
        }

        // Date range
        [$from, $to] = $this->getDateRange();
        if ($from) {
            $query->where('leads.created_at', '>=', $from);
        }
        if ($to) {
            $query->where('leads.created_at', '<=', $to->endOfDay());
        }

        return $query;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    protected function getDateRange(): array
    {
        if ($this->dateFrom || $this->dateTo) {
            return [
                $this->dateFrom ? Carbon::parse($this->dateFrom) : null,
                $this->dateTo ? Carbon::parse($this->dateTo) : null,
            ];
        }

        return match ($this->preset) {
            'today' => [now()->startOfDay(), now()],
            '7'     => [now()->subDays(7), now()],
            '30'    => [now()->subDays(30), now()],
            '90'    => [now()->subDays(90), now()],
            default => [null, null],
        };
    }

    /** @return array<string, mixed> */
    protected function computeKpis(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $total = (clone $query)->count();

        $wonPhaseIds  = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Won);
        $lostPhaseIds = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Lost);

        $won  = (clone $query)->whereIn(Lead::fkColumn('lead_phase'), $wonPhaseIds)->count();
        $lost = (clone $query)->whereIn(Lead::fkColumn('lead_phase'), $lostPhaseIds)->count();

        $conversionRate = ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0;
        $avgValue       = (clone $query)->whereNotNull('value')->where('value', '>', 0)->avg('value') ?? 0;

        return [
            'total'           => $total,
            'new'             => $total,
            'won'             => $won,
            'lost'            => $lost,
            'conversion_rate' => $conversionRate,
            'avg_value'       => round((float) $avgValue, 2),
        ];
    }

    /** @return array<string, mixed> */
    protected function computeTrend(\Illuminate\Database\Eloquent\Builder $query): array
    {
        [$from, $to] = $this->getDateRange();
        $days        = $from && $to ? $from->diffInDays($to) : 365;
        $groupBy     = $days <= 30 ? 'day' : 'week';

        $format    = 'day' === $groupBy ? '%Y-%m-%d' : '%x-%v';
        $phpFormat = 'day' === $groupBy ? 'Y-m-d' : 'o-W';

        $wonPhaseIds  = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Won);
        $lostPhaseIds = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Lost);

        $wonIn  = $this->inPlaceholders($wonPhaseIds);
        $lostIn = $this->inPlaceholders($lostPhaseIds);

        $rows = (clone $query)
            ->selectRaw("DATE_FORMAT(leads.created_at, '{$format}') as period")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN ' . Lead::fkColumn('lead_phase') . ' IN (' . $wonIn['sql'] . ') THEN 1 ELSE 0 END) as won', $wonIn['bindings'])
            ->selectRaw('SUM(CASE WHEN ' . Lead::fkColumn('lead_phase') . ' IN (' . $lostIn['sql'] . ') THEN 1 ELSE 0 END) as lost', $lostIn['bindings'])
            ->groupByRaw("DATE_FORMAT(leads.created_at, '{$format}')")
            ->orderByRaw("DATE_FORMAT(leads.created_at, '{$format}')")
            ->get();

        return [
            'labels' => $rows->pluck('period')->toArray(),
            'total'  => $rows->pluck('total')->toArray(),
            'won'    => $rows->pluck('won')->toArray(),
            'lost'   => $rows->pluck('lost')->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    protected function computeMatrix(): array
    {
        $boards = $this->getAccessibleBoards();

        [$from, $to] = $this->getDateRange();
        $user        = auth()->user();
        $result      = [];

        foreach ($boards as $board) {
            $phases = $board->phases()->ordered()->get();

            $query = Lead::query()->where(Lead::fkColumn('lead_board'), $board->getKey());
            if ($user && $board->isAdmin($user)) {
                // Admin sees all
            } elseif ($user) {
                $query->where('assigned_to', $user->getKey());
            }
            if ($from) {
                $query->where('leads.created_at', '>=', $from);
            }
            if ($to) {
                $query->where('leads.created_at', '<=', $to->endOfDay());
            }

            $rows = (clone $query)
                ->select('assigned_to', Lead::fkColumn('lead_phase'), DB::raw('COUNT(*) as cnt'))
                ->groupBy('assigned_to', Lead::fkColumn('lead_phase'))
                ->get();

            // Build berater map
            $beraterIds = $rows->pluck('assigned_to')->filter()->unique();
            $userModel  = config('lead-pipeline.user_model');
            $berater    = $beraterIds->isNotEmpty()
                ? $userModel::whereIn($userModel::query()->getModel()->getKeyName(), $beraterIds)->get()->keyBy(fn ($u) => $u->getKey())
                : collect();

            $matrix = [];
            foreach ($rows->groupBy('assigned_to') as $assignedTo => $group) {
                $name = '' === $assignedTo || null === $assignedTo
                    ? __('lead-pipeline::lead-pipeline.field.not_assigned')
                    : ($berater[$assignedTo]?->name ?? __('lead-pipeline::lead-pipeline.field.unknown'));

                $row = ['berater' => $name, 'phases' => [], 'total' => 0];
                foreach ($phases as $phase) {
                    $count                           = $group->where(Lead::fkColumn('lead_phase'), $phase->getKey())->sum('cnt');
                    $row['phases'][$phase->getKey()] = (int) $count;
                    $row['total'] += (int) $count;
                }
                $matrix[] = $row;
            }

            // Totals row
            $totals = ['berater' => __('lead-pipeline::lead-pipeline.analytics.matrix_total'), 'phases' => [], 'total' => 0];
            foreach ($phases as $phase) {
                $sum                                = collect($matrix)->sum(fn ($r) => $r['phases'][$phase->getKey()] ?? 0);
                $totals['phases'][$phase->getKey()] = $sum;
                $totals['total'] += $sum;
            }

            $result[] = [
                'board'  => $board->name,
                'phases' => $phases->map(fn ($p) => ['id' => $p->getKey(), 'name' => $p->name, 'color' => $p->color])->toArray(),
                'rows'   => $matrix,
                'totals' => $totals,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    protected function computeSources(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $wonPhaseIds  = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Won);
        $lostPhaseIds = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Lost);
        $wonIn        = $this->inPlaceholders($wonPhaseIds);
        $lostIn       = $this->inPlaceholders($lostPhaseIds);

        return (clone $query)
            ->join('lead_sources', 'leads.' . Lead::fkColumn('lead_source'), '=', 'lead_sources.' . Lead::pkColumn())
            ->select('lead_sources.name as source_name')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN ' . Lead::fkColumn('lead_phase') . ' IN (' . $wonIn['sql'] . ') THEN 1 ELSE 0 END) as won', $wonIn['bindings'])
            ->selectRaw('SUM(CASE WHEN ' . Lead::fkColumn('lead_phase') . ' IN (' . $lostIn['sql'] . ') THEN 1 ELSE 0 END) as lost', $lostIn['bindings'])
            ->selectRaw('AVG(CASE WHEN leads.value > 0 THEN leads.value ELSE NULL END) as avg_value')
            ->groupBy('lead_sources.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'source'     => $row->source_name,
                'total'      => (int) $row->total,
                'won'        => (int) $row->won,
                'lost'       => (int) $row->lost,
                'conversion' => ($row->won + $row->lost) > 0 ? round($row->won / ($row->won + $row->lost) * 100, 1) : 0,
                'avg_value'  => round((float) ($row->avg_value ?? 0), 2),
            ])
            ->toArray();
    }

    /** @return array<string, mixed> */
    protected function computeBeraterChart(): array
    {
        // Reuse matrix data
        if (empty($this->matrixData)) {
            return [];
        }

        $labels     = [];
        $open       = [];
        $inProgress = [];
        $won        = [];
        $lost       = [];

        foreach ($this->matrixData as $boardData) {
            $phases       = collect($boardData['phases']);
            $openPhaseIds = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Open);
            $ipPhaseIds   = $this->getPhaseIdsByType(LeadPhaseTypeEnum::InProgress);
            $wonPhaseIds  = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Won);
            $lostPhaseIds = $this->getPhaseIdsByType(LeadPhaseTypeEnum::Lost);

            foreach ($boardData['rows'] as $row) {
                $label = count($this->matrixData) > 1
                    ? $boardData['board'] . ' — ' . $row['berater']
                    : $row['berater'];
                $labels[] = $label;

                $openSum = $ipSum = $wonSum = $lostSum = 0;
                foreach ($row['phases'] as $phaseId => $count) {
                    if (in_array($phaseId, $openPhaseIds, true)) {
                        $openSum += $count;
                    } elseif (in_array($phaseId, $ipPhaseIds, true)) {
                        $ipSum += $count;
                    } elseif (in_array($phaseId, $wonPhaseIds, true)) {
                        $wonSum += $count;
                    } elseif (in_array($phaseId, $lostPhaseIds, true)) {
                        $lostSum += $count;
                    }
                }
                $open[]       = $openSum;
                $inProgress[] = $ipSum;
                $won[]        = $wonSum;
                $lost[]       = $lostSum;
            }
        }

        return [
            'labels'     => $labels,
            'open'       => $open,
            'inProgress' => $inProgress,
            'won'        => $won,
            'lost'       => $lost,
        ];
    }

    /** @return array<string, mixed> */
    protected function computeSourcesChart(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return [
            'labels' => collect($this->sourcesData)->pluck('source')->toArray(),
            'data'   => collect($this->sourcesData)->pluck('total')->toArray(),
        ];
    }

    /** @return array<string> */
    protected function getPhaseIdsByType(LeadPhaseTypeEnum $type): array
    {
        if ($this->boardId) {
            return LeadPhase::where(LeadPhase::fkColumn('lead_board'), $this->boardId)
                ->where('type', $type)->pluck(LeadPhase::pkColumn())->toArray();
        }

        $tenantFk = config('lead-pipeline.tenancy.foreign_key');
        $tenantId = filament()->getTenant()?->getKey();
        $boardIds = LeadBoard::where($tenantFk, $tenantId)->pluck(LeadBoard::pkColumn());

        return LeadPhase::whereIn(LeadPhase::fkColumn('lead_board'), $boardIds)
            ->where('type', $type)->pluck(LeadPhase::pkColumn())->toArray();
    }

    /** @return Collection<int, LeadBoard> */
    protected function getAccessibleBoards(): Collection
    {
        if ($this->boardId) {
            return collect([LeadBoard::find($this->boardId)])->filter();
        }

        $tenantFk = config('lead-pipeline.tenancy.foreign_key');
        $tenantId = filament()->getTenant()?->getKey();
        $user     = auth()->user();

        if ( ! $tenantId || ! $user) {
            return collect();
        }

        // Admins: return boards where user is admin
        $adminBoards = LeadBoard::where($tenantFk, $tenantId)
            ->whereHas('admins', fn ($q) => $q->where('lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'), $user->getKey()))
            ->get();

        if ($adminBoards->isNotEmpty()) {
            return $adminBoards;
        }

        // Non-admin: return all team boards (visibility is handled per-query)
        return LeadBoard::where($tenantFk, $tenantId)->get();
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
