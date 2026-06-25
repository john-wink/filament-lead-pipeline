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
use JohnWink\FilamentLeadPipeline\Services\MetaApiErrorInterpreter;
use JohnWink\FilamentLeadPipeline\Services\MetaConversionsDatasetResolver;
use RuntimeException;
use Throwable;

/**
 * Meldet das Ergebnis eines Meta-Leads (gewonnen / verloren / nicht qualifiziert)
 * über die Conversions API an Meta zurück. Multi-Tenant: Dataset-ID und Token
 * werden PRO LEAD aufgelöst (über MetaConversionsDatasetResolver), nicht global.
 *
 * Es wird IMMER versucht zu senden, sobald Token + Dataset vorhanden sind. Lehnt
 * Meta den POST ab, wird der Fehler über MetaApiErrorInterpreter übersetzt und als
 * Warnung mit der KONKRET fehlenden Berechtigung/Aktion geloggt (statt nur dem
 * HTTP-Status). Transiente Fehler (Rate-Limit, Netzwerk) werden erneut versucht;
 * Berechtigungs-/Token-Fehler nicht (Retry würde nicht helfen).
 */
class ReportLeadOutcomeToMeta implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public string $leadId,
        public string $eventName,
    ) {}

    public function handle(MetaConversionsDatasetResolver $resolver, MetaApiErrorInterpreter $interpreter): void
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
                            'event_time'    => $lead->updated_at?->getTimestamp() ?? now()->getTimestamp(),
                            'action_source' => 'system_generated',
                            'user_data'     => [
                                'lead_id' => (string) $lead->external_id,
                            ],
                        ],
                    ],
                    'access_token' => $accessToken,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Meta conversion-lead feedback errored (network) — will retry', [
                'lead_id'    => $this->leadId,
                'event_name' => $this->eventName,
                'error'      => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($response->failed()) {
            $verdict = $interpreter->interpret($response->json('error'));

            Log::warning('Meta conversion-lead feedback rejected', [
                'lead_id'            => $this->leadId,
                'event_name'         => $this->eventName,
                'dataset_id'         => $datasetId,
                'http_status'        => $response->status(),
                'category'           => $verdict['category'],
                'missing_permission' => $verdict['missing_permission'],
                'required_action'    => $verdict['required_action'],
                'meta_code'          => $verdict['code'],
                'meta_subcode'       => $verdict['subcode'],
                'meta_message'       => $verdict['message'],
                'fbtrace_id'         => $verdict['fbtrace_id'],
            ]);

            if ($verdict['retryable']) {
                throw new RuntimeException(sprintf(
                    'Meta CAPI transient error (code %s) — retrying.',
                    $verdict['code'] ?? 'n/a',
                ));
            }

            return;
        }

        Log::info('Meta conversion-lead feedback sent', [
            'lead_id'    => $this->leadId,
            'event_name' => $this->eventName,
            'dataset_id' => $datasetId,
        ]);
    }
}
