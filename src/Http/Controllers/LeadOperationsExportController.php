<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Illuminate\Http\Request;
use JohnWink\FilamentLeadPipeline\Concerns\ScopesOperationsLeads;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadOperationsExportController
{
    use ScopesOperationsLeads;

    public function __invoke(Request $request, LeadActivityMetricsService $service): StreamedResponse
    {
        $boardId     = $request->query('boardId');
        $advisorId   = $request->query('advisorId');
        [$from, $to] = $this->operationsRange(
            $request->query('dateFrom'),
            $request->query('dateTo'),
            (string) $request->query('preset', '30'),
        );

        $leads = $this->scopedOperationsLeads($boardId, $advisorId);

        $ranking = $service->advisorOps((clone $leads), $from, $to);
        $sources = $service->sourceEconomics((clone $leads), $from, $to);

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
            fputcsv($handle, [
                'Quelle',
                'Leads',
                'Gewonnen',
                'Conversion %',
                __('lead-pipeline::lead-pipeline.operations.cost_per_lead'),
                __('lead-pipeline::lead-pipeline.operations.cost_per_acquisition'),
                'Ø Wert',
            ], ';');
            foreach ($sources as $src) {
                fputcsv($handle, [
                    $src['source'],
                    $src['leads'],
                    $src['won'],
                    number_format($src['conversion'], 1, ',', '.'),
                    null === $src['cost_per_lead'] ? '' : number_format($src['cost_per_lead'], 2, ',', '.'),
                    null === $src['cost_per_acquisition'] ? '' : number_format($src['cost_per_acquisition'], 2, ',', '.'),
                    number_format($src['avg_value'], 2, ',', '.'),
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
