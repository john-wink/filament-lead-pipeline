<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Concerns\ScopesOperationsLeads;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

class LeadOperations extends Page
{
    use ScopesOperationsLeads;

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

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    protected function range(): array
    {
        return $this->operationsRange($this->dateFrom, $this->dateTo, $this->preset);
    }

    protected function scopedLeads(): Builder
    {
        return $this->scopedOperationsLeads($this->boardId, $this->advisorId);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $isLeadership = $this->isOperationsLeadership($this->boardId);
        if ( ! $isLeadership) {
            $this->advisorId = (string) auth()->id();
        }

        $service     = app(LeadActivityMetricsService::class);
        [$from, $to] = $this->range();
        $leads       = fn (): Builder => $this->scopedLeads();

        $board = $this->boardId ? LeadBoard::find($this->boardId) : null;

        $matrix = $service->advisorActivityMatrix($leads(), $from, $to);
        if ( ! $isLeadership) {
            // Zusätzlicher Gurt trotz serverseitig erzwungener advisorId: der
            // eigentliche Leak-Vektor ist, dass advisorActivityMatrix() die
            // Aktivitäts-Zählungen nach causer_id gruppiert — ein Kollege, der
            // auf einem MEINER Leads eine Notiz/einen Anruf loggt, erschiene
            // sonst als eigene fremde Zeile. (Sekundär deckt der Filter auch den
            // Board-Zweig ab, in dem Lead::scopeVisibleTo() bei mit dem Tenant
            // geteilten Boards alle Leads unabhängig vom Assignee freigibt.)
            // Nur die eigene Zeile bleibt; das Team-Aggregat bleibt als Vergleich.
            $matrix['rows'] = array_values(array_filter(
                $matrix['rows'],
                fn (array $row): bool => $row['advisor_id'] === (string) auth()->id(),
            ));
        }

        return [
            'boards' => (function_exists('filament') && filament()->getTenant())
                ? LeadBoard::visibleToTenant(filament()->getTenant())->pluck('name', LeadBoard::pkColumn())->all()
                : LeadBoard::query()->pluck('name', LeadBoard::pkColumn())->all(),
            'advisorOptions' => $this->advisorOptions(),
            'isLeadership'   => $isLeadership,
            'response'       => $service->responseStats($leads(), $from, $to),
            'operations'     => $service->operationsStats($leads()),
            'stageDwell'     => $service->stageDwell($leads(), $from, $to),
            'heatmap'        => $service->contactHeatmap($leads(), $from, $to),
            'velocity'       => $service->pipelineVelocity($leads()),
            'funnel'         => $board ? $service->funnel($board) : [],
            'lossReasons'    => $service->lossReasons($leads(), $from, $to),
            'sources'        => $service->sourceEconomics($leads(), $from, $to),
            'matrix'         => $matrix,
        ];
    }

    /** @return array<string, string> */
    protected function advisorOptions(): array
    {
        // Options must ignore the current advisor selection, otherwise the
        // select collapses to the selected advisor and blocks switching A→B.
        $ids = $this->scopedOperationsLeads($this->boardId, null)
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
