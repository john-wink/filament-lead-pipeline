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
use Throwable;

/**
 * Meldet das Ergebnis eines Meta-Leads (gewonnen / verloren / nicht qualifiziert)
 * über die Conversions API an Meta zurück. Config-gated und nicht blockierend —
 * Fehler werden geloggt, nicht eskaliert.
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
        public string $leadgenId,
        public string $eventName,
    ) {}

    public function handle(): void
    {
        $config = config('lead-pipeline.meta.conversions');

        if (empty($config['enabled']) || blank($config['dataset_id'] ?? null) || blank($config['access_token'] ?? null)) {
            return;
        }

        $graphVersion = (string) ($config['graph_version'] ?? 'v21.0');
        $datasetId    = (string) $config['dataset_id'];
        $accessToken  = (string) $config['access_token'];

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
                                'lead_id' => $this->leadgenId,
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
                    'http_status' => $response->status(),
                ]);

                return;
            }

            Log::info('Meta conversion-lead feedback sent', [
                'lead_id'    => $this->leadId,
                'event_name' => $this->eventName,
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
