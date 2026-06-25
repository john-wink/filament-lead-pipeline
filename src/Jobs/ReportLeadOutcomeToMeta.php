<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Services\MetaConversionsDatasetResolver;
use Throwable;

/**
 * Meldet das Ergebnis eines Meta-Leads (gewonnen / verloren / nicht qualifiziert)
 * über die Conversions API an Meta zurück. Multi-Tenant: Dataset-ID und Token
 * werden PRO LEAD aufgelöst (über MetaConversionsDatasetResolver), nicht global.
 * Config-gated und nicht blockierend — Fehler werden geloggt, nicht eskaliert.
 */
class ReportLeadOutcomeToMeta implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $leadId,
        public string $eventName,
    ) {}

    public function handle(MetaConversionsDatasetResolver $resolver): void
    {
        if ( ! config('lead-pipeline.meta.conversions.enabled')) {
            return;
        }

        $lead = Lead::query()->find($this->leadId);

        if ( ! $lead || blank($lead->external_id)) {
            Log::info('Meta conversion-lead feedback skipped: lead missing or no leadgen id', [
                'lead_id' => $this->leadId,
            ]);

            return;
        }

        $accessToken = $resolver->resolveAccessToken($lead);

        if (blank($accessToken)) {
            Log::info('Meta conversion-lead feedback skipped: no source connection token', [
                'lead_id' => $this->leadId,
            ]);

            return;
        }

        $datasetId = $resolver->resolve($lead);

        if (blank($datasetId)) {
            Log::info('Meta conversion-lead feedback skipped: no dataset for lead ad', [
                'lead_id' => $this->leadId,
                'ad_id'   => (string) ($lead->source_ad_id ?? ''),
            ]);

            return;
        }

        $graphVersion = (string) config('lead-pipeline.meta.conversions.graph_version', 'v21.0');

        try {
            $response = Http::asJson()->post(
                "https://graph.facebook.com/{$graphVersion}/{$datasetId}/events",
                [
                    'data' => [
                        [
                            'event_name'    => $this->eventName,
                            'event_time'    => now()->timestamp,
                            'action_source' => 'system_generated',
                            'user_data'     => [
                                'lead_id' => (string) $lead->external_id,
                            ],
                        ],
                    ],
                    'access_token' => $accessToken,
                ],
            );

            if ($response->failed()) {
                Log::warning('Meta conversion-lead feedback failed', [
                    'lead_id'     => $this->leadId,
                    'event_name'  => $this->eventName,
                    'dataset_id'  => $datasetId,
                    'http_status' => $response->status(),
                ]);

                return;
            }

            Log::info('Meta conversion-lead feedback sent', [
                'lead_id'    => $this->leadId,
                'event_name' => $this->eventName,
                'dataset_id' => $datasetId,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Meta conversion-lead feedback errored', [
                'lead_id'    => $this->leadId,
                'event_name' => $this->eventName,
                'error'      => $exception->getMessage(),
            ]);
        }
    }
}
