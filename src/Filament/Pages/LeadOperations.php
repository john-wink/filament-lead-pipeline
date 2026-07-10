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

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string $view = 'lead-pipeline::filament.pages.lead-operations';

    protected static ?string $slug = 'lead-operations';

    public static function getNavigationLabel(): string
    {
        return __('lead-pipeline::lead-pipeline.operations.nav');
    }

    /**
     * Nest under the "Leads" navigation item (LeadBoardResource) instead of
     * cluttering the top level with a second entry — the parent label must
     * match LeadBoardResource::getNavigationLabel() exactly.
     */
    public static function getNavigationParentItem(): ?string
    {
        return config('lead-pipeline.navigation.label', __('lead-pipeline::lead-pipeline.navigation.label'));
    }

    public static function getNavigationGroup(): ?string
    {
        return config('lead-pipeline.navigation.group');
    }

    public static function getNavigationSort(): ?int
    {
        return ((int) config('lead-pipeline.navigation.sort', 10)) + 2;
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
        $this->preset   = in_array($preset, ['today', '7', '30', '90', 'all'], true) ? $preset : '30';
        $this->dateFrom = null;
        $this->dateTo   = null;
    }

    public function setAdvisor(?string $advisorId): void
    {
        $this->advisorId = ('' === $advisorId || 'all' === $advisorId) ? null : $advisorId;
    }

    public function updatedDateFrom(): void
    {
        $this->preset = 'custom';
    }

    public function updatedDateTo(): void
    {
        $this->preset = 'custom';
    }

    public function getExportUrl(): string
    {
        return route('lead-pipeline.operations.export', array_filter([
            'boardId'   => $this->boardId,
            'preset'    => $this->preset,
            'advisorId' => $this->advisorId,
            'dateFrom'  => $this->dateFrom,
            'dateTo'    => $this->dateTo,
        ]));
    }

    /**
     * Custom-Datum hat Vorrang vor dem Preset (Muster LeadAnalyticsModal::getDateRange()).
     * 'all' und unbelegte Custom-Grenzen liefern null — Metriken filtern dann nicht.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    protected function range(): array
    {
        if (null !== $this->dateFrom || null !== $this->dateTo) {
            return [
                $this->dateFrom ? CarbonImmutable::parse($this->dateFrom)->startOfDay() : null,
                $this->dateTo ? CarbonImmutable::parse($this->dateTo)->endOfDay() : null,
            ];
        }

        $now = CarbonImmutable::now();

        return match ($this->preset) {
            'today' => [$now->startOfDay(), $now],
            '7'     => [$now->subDays(7), $now],
            '90'    => [$now->subDays(90), $now],
            'all'   => [null, null],
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

        // Interim bis Task 4 die Service-Signaturen nullable macht.
        $rangeFrom = $from ?? CarbonImmutable::parse('1970-01-01');
        $rangeTo   = $to ?? CarbonImmutable::now();

        return [
            'boards' => (function_exists('filament') && filament()->getTenant())
                ? LeadBoard::visibleToTenant(filament()->getTenant())->pluck('name', LeadBoard::pkColumn())->all()
                : LeadBoard::query()->pluck('name', LeadBoard::pkColumn())->all(),
            'advisorOptions' => $this->advisorOptions(),
            'response'       => $service->responseStats($leads(), $rangeFrom, $rangeTo),
            'operations'     => $service->operationsStats($leads()),
            'stageDwell'     => $service->stageDwell($leads()),
            'heatmap'        => $service->contactHeatmap($leads(), $rangeFrom, $rangeTo),
            'velocity'       => $service->pipelineVelocity($leads()),
            'funnel'         => $board ? $service->funnel($board) : [],
            'lossReasons'    => $service->lossReasons($leads()),
            'sources'        => $service->sourceEconomics($leads()),
            'ranking'        => $service->advisorOps($leads(), $rangeFrom, $rangeTo),
        ];
    }

    /** @return array<string, string> */
    protected function advisorOptions(): array
    {
        $ids = (clone $this->scopedLeads())
            ->whereNotNull('leads.assigned_to')
            ->reorder()
            ->select('leads.assigned_to')
            ->distinct()
            ->pluck('assigned_to');

        $userModel = config('lead-pipeline.user_model');

        return $userModel::query()
            ->whereIn((new $userModel())->getKeyName(), $ids)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($u): array => [(string) $u->getKey() => (string) $u->name])
            ->all();
    }
}
