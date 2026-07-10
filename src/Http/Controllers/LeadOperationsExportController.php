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
        $boardId   = $request->query('boardId');
        $advisorId = $request->query('advisorId');
        if ( ! $this->isOperationsLeadership($boardId)) {
            $advisorId = (string) auth()->id();
        }

        [$from, $to] = $this->operationsRange(
            $request->query('dateFrom'),
            $request->query('dateTo'),
            (string) $request->query('preset', '30'),
        );

        $leads = $this->scopedOperationsLeads($boardId, $advisorId);

        $matrix  = $service->advisorActivityMatrix((clone $leads), $from, $to);
        $sources = $service->sourceEconomics((clone $leads), $from, $to);

        $filename = 'lead-ops-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($matrix, $sources): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['=== ' . __('lead-pipeline::lead-pipeline.operations.matrix_title') . ' ==='], ';');
            fputcsv($handle, [
                __('lead-pipeline::lead-pipeline.operations.matrix_advisor'),
                __('lead-pipeline::lead-pipeline.operations.calls'),
                __('lead-pipeline::lead-pipeline.operations.emails'),
                __('lead-pipeline::lead-pipeline.operations.notes'),
                __('lead-pipeline::lead-pipeline.operations.moves'),
                __('lead-pipeline::lead-pipeline.operations.first_contacts'),
                __('lead-pipeline::lead-pipeline.operations.assigned_new'),
                __('lead-pipeline::lead-pipeline.operations.won'),
                __('lead-pipeline::lead-pipeline.operations.lost'),
                'Conversion %', 'Ø Reaktion (min)', 'SLA %',
                __('lead-pipeline::lead-pipeline.operations.activities_per_lead'),
                __('lead-pipeline::lead-pipeline.operations.score'),
                __('lead-pipeline::lead-pipeline.operations.score_activity'),
                __('lead-pipeline::lead-pipeline.operations.score_tempo'),
                __('lead-pipeline::lead-pipeline.operations.score_result'),
                __('lead-pipeline::lead-pipeline.operations.score_diligence'),
            ], ';');
            foreach ($matrix['rows'] as $row) {
                fputcsv($handle, [
                    $row['advisor_name'], $row['calls'], $row['emails'], $row['notes'], $row['moves'],
                    $row['first_contacts'], $row['assigned_new'], $row['won'], $row['lost'],
                    number_format($row['conversion'], 1, ',', '.'),
                    null === $row['avg_response_minutes'] ? '' : number_format($row['avg_response_minutes'], 1, ',', '.'),
                    number_format($row['sla_pct'], 1, ',', '.'),
                    null === $row['activities_per_lead'] ? '' : number_format($row['activities_per_lead'], 2, ',', '.'),
                    number_format($row['scores']['total'], 1, ',', '.'),
                    number_format($row['scores']['activity'], 1, ',', '.'),
                    number_format($row['scores']['tempo'], 1, ',', '.'),
                    number_format($row['scores']['result'], 1, ',', '.'),
                    number_format($row['scores']['diligence'], 1, ',', '.'),
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
