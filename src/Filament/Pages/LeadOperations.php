<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

class LeadOperations extends Page
{
    public ?string $boardId = null;

    public string $preset = '30';

    public ?string $advisorId = null;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string $view = 'lead-pipeline::filament.pages.lead-operations';

    protected static ?string $slug = 'lead-operations';

    public static function getNavigationLabel(): string
    {
        return __('lead-pipeline::lead-pipeline.operations.nav');
    }

    public function getTitle(): string
    {
        return __('lead-pipeline::lead-pipeline.operations.title');
    }

    public function setBoard(?string $boardId): void
    {
        $this->boardId = ('' === $boardId || 'all' === $boardId) ? null : $boardId;
    }

    public function setPreset(string $preset): void
    {
        $this->preset = in_array($preset, ['today', '7', '30', '90'], true) ? $preset : '30';
    }

    public function setAdvisor(?string $advisorId): void
    {
        $this->advisorId = ('' === $advisorId || 'all' === $advisorId) ? null : $advisorId;
    }

    public function getExportUrl(): string
    {
        return route('lead-pipeline.operations.export', array_filter([
            'boardId'   => $this->boardId,
            'preset'    => $this->preset,
            'advisorId' => $this->advisorId,
        ]));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function range(): array
    {
        $now = CarbonImmutable::now();

        return match ($this->preset) {
            'today' => [$now->startOfDay(), $now],
            '7'     => [$now->subDays(7), $now],
            '90'    => [$now->subDays(90), $now],
            default => [$now->subDays(30), $now],
        };
    }

    protected function scopedLeads(): Builder
    {
        $query = Lead::query();
        $user  = auth()->user();

        if ($this->boardId) {
            $board = LeadBoard::find($this->boardId);
            if ($board && ! $board->isAccessibleByTenant(filament()->getTenant())) {
                abort(403);
            }

            // Column names are qualified with the leads table: some service methods
            // (e.g. sourceEconomics) join lead_sources, which has its own
            // lead_board_uuid column — an unqualified where() here would become
            // ambiguous once that join is applied.
            $query->where('leads.' . Lead::fkColumn('lead_board'), $this->boardId);

            if ($board && $user) {
                $query->visibleTo($user, $board);
            }
        } elseif (function_exists('filament')) {
            $tenantId = filament()->getTenant()?->getKey();

            if ($tenantId && $user) {
                // Mirrors AnalyticsExportController::baseQuery(): without a selected
                // board, a non-board-admin must not see leads across the whole
                // tenant — only their own, across all tenant-visible boards.
                $adminBoardIds = LeadBoard::visibleToTenant(filament()->getTenant())
                    ->whereHas('admins', fn ($q) => $q->where('lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'), $user->getKey()))
                    ->pluck(LeadBoard::pkColumn());

                if ($adminBoardIds->isEmpty()) {
                    $allBoardIds = LeadBoard::visibleToTenant(filament()->getTenant())->pluck(LeadBoard::pkColumn());
                    $query->whereIn('leads.' . Lead::fkColumn('lead_board'), $allBoardIds)
                        ->where('leads.assigned_to', $user->getKey());
                } else {
                    $query->whereIn('leads.' . Lead::fkColumn('lead_board'), $adminBoardIds);
                }
            }
        }

        if (null !== $this->advisorId) {
            $query->where('leads.assigned_to', $this->advisorId);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service     = app(LeadActivityMetricsService::class);
        [$from, $to] = $this->range();
        $leads       = fn (): Builder => $this->scopedLeads();

        $board = $this->boardId ? LeadBoard::find($this->boardId) : null;

        return [
            'boards' => (function_exists('filament') && filament()->getTenant())
                ? LeadBoard::visibleToTenant(filament()->getTenant())->pluck('name', LeadBoard::pkColumn())->all()
                : LeadBoard::query()->pluck('name', LeadBoard::pkColumn())->all(),
            'response'    => $service->responseStats($leads(), $from, $to),
            'operations'  => $service->operationsStats($leads()),
            'stageDwell'  => $service->stageDwell($leads()),
            'heatmap'     => $service->contactHeatmap($leads(), $from, $to),
            'velocity'    => $service->pipelineVelocity($leads()),
            'funnel'      => $board ? $service->funnel($board) : [],
            'lossReasons' => $service->lossReasons($leads()),
            'sources'     => $service->sourceEconomics($leads()),
            'ranking'     => $service->advisorOps($leads(), $from, $to),
        ];
    }
}
