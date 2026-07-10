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

        if (filled($advisorId)) {
            $query->where('leads.assigned_to', $advisorId);
        }

        return $query;
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
                filled($dateFrom) ? CarbonImmutable::parse($dateFrom)->startOfDay() : null,
                filled($dateTo) ? CarbonImmutable::parse($dateTo)->endOfDay() : null,
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
}
