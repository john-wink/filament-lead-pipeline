<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

/**
 * Shared lead-scoping and date-range logic for the Lead Operations surface
 * (page, export controller, and advisor scorecard panel) — a single
 * definition of the 403/visibility rules instead of three drifting copies.
 */
trait ScopesOperationsLeads
{
    protected function scopedOperationsLeads(?string $boardId, ?string $advisorId): Builder
    {
        $query = Lead::query();
        $user  = auth()->user();

        if ($boardId) {
            $board = LeadBoard::find($boardId);
            if ($board && ! $board->isAccessibleByTenant(filament()->getTenant())) {
                abort(403);
            }

            // Column names are qualified with the leads table: some service methods
            // (e.g. sourceEconomics) join lead_sources, which has its own
            // lead_board_uuid column — an unqualified where() here would become
            // ambiguous once that join is applied.
            $query->where('leads.' . Lead::fkColumn('lead_board'), $boardId);

            if ($board && $user) {
                $query->visibleTo($user, $board);
            }
        } else {
            // No board filter: without a resolvable tenant AND user, we cannot prove
            // the caller is scoped to a single tenant — fail closed instead of
            // returning an unscoped (cross-tenant) query.
            $tenant = function_exists('filament') ? filament()->getTenant() : null;

            if (null === $tenant || null === $user) {
                abort(403);
            }

            // Mirrors AnalyticsExportController::baseQuery(): without a selected
            // board, a non-board-admin must not see leads across the whole
            // tenant — only their own, across all tenant-visible boards.
            $adminBoardIds = LeadBoard::visibleToTenant($tenant)
                ->whereHas('admins', fn ($q) => $q->where('lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'), $user->getKey()))
                ->pluck(LeadBoard::pkColumn());

            if ($adminBoardIds->isEmpty()) {
                $allBoardIds = LeadBoard::visibleToTenant($tenant)->pluck(LeadBoard::pkColumn());
                $query->whereIn('leads.' . Lead::fkColumn('lead_board'), $allBoardIds)
                    ->where('leads.assigned_to', $user->getKey());
            } else {
                $query->whereIn('leads.' . Lead::fkColumn('lead_board'), $adminBoardIds);
            }
        }

        if (filled($advisorId)) {
            $query->where('leads.assigned_to', $advisorId);
        }

        return $query;
    }

    /**
     * Führung = Admin mindestens eines tenant-sichtbaren Boards (bzw. des gewählten Boards).
     */
    protected function isOperationsLeadership(?string $boardId): bool
    {
        $user = auth()->user();
        if (null === $user || ! function_exists('filament') || null === filament()->getTenant()) {
            return false;
        }

        $adminBoards = LeadBoard::visibleToTenant(filament()->getTenant())
            ->when(null !== $boardId, fn ($q) => $q->where(LeadBoard::pkColumn(), $boardId))
            ->whereHas('admins', fn ($q) => $q->where(
                'lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid'),
                $user->getKey(),
            ));

        return $adminBoards->exists();
    }

    /**
     * Custom-Datum hat Vorrang vor dem Preset (Muster LeadAnalyticsModal::getDateRange()).
     * 'all' und unbelegte Custom-Grenzen liefern null — Metriken filtern dann nicht.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    protected function operationsRange(?string $dateFrom, ?string $dateTo, string $preset): array
    {
        if (filled($dateFrom) || filled($dateTo)) {
            return [
                filled($dateFrom) ? $this->parseOperationsDateBound($dateFrom)?->startOfDay() : null,
                filled($dateTo) ? $this->parseOperationsDateBound($dateTo)?->endOfDay() : null,
            ];
        }

        $now = CarbonImmutable::now();

        return match ($preset) {
            'today' => [$now->startOfDay(), $now],
            '7'     => [$now->subDays(7), $now],
            '90'    => [$now->subDays(90), $now],
            'all'   => [null, null],
            default => [$now->subDays(30), $now],
        };
    }

    /**
     * A malformed dateFrom/dateTo query param (or livewire property) must degrade to an
     * unbounded edge instead of throwing — this is reachable directly from an
     * unauthenticated-shaped query string on the export route, so a parse failure must
     * never surface as a 500.
     */
    protected function parseOperationsDateBound(string $value): ?CarbonImmutable
    {
        return rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value), null, false);
    }

    /**
     * Non-leadership callers only ever see their own advisor row — applied identically
     * on the page (getViewData()) and the export controller so the two surfaces cannot
     * drift. The underlying leak vector: advisorActivityMatrix() groups activity counts
     * by causer_id, so a colleague logging a call/note on MY lead would otherwise appear
     * as their own foreign row (this also covers the board branch of
     * Lead::scopeVisibleTo(), which — for boards shared with the tenant — releases all
     * leads regardless of assignee).
     *
     * @param  array<string, mixed>  $matrix
     * @return array<string, mixed>
     */
    protected function filterMatrixRowsForNonLeadership(array $matrix, ?string $boardId): array
    {
        if ($this->isOperationsLeadership($boardId)) {
            return $matrix;
        }

        $matrix['rows'] = array_values(array_filter(
            $matrix['rows'],
            fn (array $row): bool => $row['advisor_id'] === (string) auth()->id(),
        ));

        return $matrix;
    }
}
