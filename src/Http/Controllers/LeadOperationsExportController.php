<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadOperationsExportController
{
    public function __invoke(Request $request, LeadActivityMetricsService $service): StreamedResponse
    {
        $boardId     = $request->query('boardId');
        $advisorId   = $request->query('advisorId');
        [$from, $to] = $this->range((string) $request->query('preset', '30'));

        $leads = $this->scopedLeads($boardId, $advisorId);

        $ranking = $service->advisorOps((clone $leads), $from, $to);
        $sources = $service->sourceEconomics((clone $leads));

        $filename = 'lead-ops-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($ranking, $sources): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.operations.ops_ranking') . ' ==='], ';');
            fputcsv($handle, ['Berater', 'Ops-Score', 'SLA %', 'Ø Reaktion (min)', 'Kontaktversuche', 'Gewonnen'], ';');
            foreach ($ranking as $row) {
                fputcsv($handle, [
                    $row['advisor_id'] ?? '—',
                    number_format($row['ops_score'], 1, ',', '.'),
                    number_format($row['sla_pct'], 1, ',', '.'),
                    null === $row['avg_response_minutes'] ? '' : number_format($row['avg_response_minutes'], 1, ',', '.'),
                    $row['contact_attempts'],
                    $row['won'],
                ], ';');
            }
            fputcsv($handle, [], ';');

            fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.operations.source_economics') . ' ==='], ';');
            fputcsv($handle, ['Quelle', 'Leads', 'Gewonnen', 'Conversion %', 'Ø Wert'], ';');
            foreach ($sources as $src) {
                fputcsv($handle, [
                    $src['source'],
                    $src['leads'],
                    $src['won'],
                    number_format($src['conversion'], 1, ',', '.'),
                    number_format($src['avg_value'], 2, ',', '.'),
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function scopedLeads(?string $boardId, ?string $advisorId): Builder
    {
        $leads = Lead::query();
        $user  = auth()->user();

        if ($boardId) {
            $board = LeadBoard::find($boardId);
            if ($board && ! $board->isAccessibleByTenant(filament()->getTenant())) {
                abort(403);
            }

            // Column names are qualified with the leads table: sourceEconomics()
            // joins lead_sources, which has its own lead_board_uuid column — an
            // unqualified where() here would become ambiguous once that join is
            // applied. Mirrors LeadOperations::scopedLeads().
            $leads->where('leads.' . Lead::fkColumn('lead_board'), $boardId);

            if ($board && $user) {
                $leads->visibleTo($user, $board);
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
                    $leads->whereIn('leads.' . Lead::fkColumn('lead_board'), $allBoardIds)
                        ->where('leads.assigned_to', $user->getKey());
                } else {
                    $leads->whereIn('leads.' . Lead::fkColumn('lead_board'), $adminBoardIds);
                }
            }
        }

        if (filled($advisorId)) {
            $leads->where('leads.assigned_to', $advisorId);
        }

        return $leads;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $preset): array
    {
        $now = CarbonImmutable::now();

        return match ($preset) {
            'today' => [$now->startOfDay(), $now],
            '7'     => [$now->subDays(7), $now],
            '90'    => [$now->subDays(90), $now],
            default => [$now->subDays(30), $now],
        };
    }
}
