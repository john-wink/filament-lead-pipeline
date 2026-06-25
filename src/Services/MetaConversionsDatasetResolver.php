<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use Throwable;

/**
 * Löst die Meta-Conversions-API-Dataset-ID PRO LEAD auf (Multi-Tenant).
 *
 * Das Dataset einer Conversion-Leads-Anzeige ist die `promoted_object.pixel_id`
 * ihres Adsets. Wir lesen sie über die an der Anzeige hinterlegte Facebook-Ad-ID
 * (`source_ad_id`, bei der Lead-Erfassung gespeichert) mit dem User-Token der
 * Quell-FacebookConnection des Leads (benötigt `ads_read`). Das Ergebnis wird
 * pro Ad-ID gecacht — inkl. negativem Ergebnis, damit Treffer-Misses Graph nicht
 * wiederholt belasten. Bei JEDEM Fehler wird null zurückgegeben, niemals geworfen.
 */
class MetaConversionsDatasetResolver
{
    private const NO_DATASET_SENTINEL = '__none__';

    private string $graphUrl = 'https://graph.facebook.com';

    public function __construct(private readonly MetaApiErrorInterpreter $interpreter) {}

    public function resolve(Lead $lead): ?string
    {
        $adId = (string) ($lead->source_ad_id ?? '');

        if (blank($adId)) {
            return null;
        }

        $cacheKey = $this->cacheKey($adId);
        $cached   = Cache::get($cacheKey);

        if (null !== $cached) {
            return self::NO_DATASET_SENTINEL === $cached ? null : (string) $cached;
        }

        $datasetId = $this->fetchDatasetId($lead, $adId);

        Cache::put(
            $cacheKey,
            $datasetId ?? self::NO_DATASET_SENTINEL,
            $this->cacheTtl(),
        );

        return $datasetId;
    }

    /**
     * Löst das User-Token der Quell-FacebookConnection des Leads auf
     * (Lead → LeadSource → FacebookPage → FacebookConnection->access_token).
     */
    public function resolveAccessToken(Lead $lead): ?string
    {
        $token = $lead->source?->facebookPage?->connection?->access_token;

        return blank($token) ? null : (string) $token;
    }

    private function fetchDatasetId(Lead $lead, string $adId): ?string
    {
        $token = $this->resolveAccessToken($lead);

        if (null === $token) {
            Log::info('Meta dataset resolution skipped: no source connection token', [
                'lead_id' => (string) $lead->getKey(),
                'ad_id'   => $adId,
            ]);

            return null;
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get("{$this->graphUrl}/{$this->graphVersion()}/{$adId}", [
                    'access_token' => $token,
                    'fields'       => 'adset{promoted_object}',
                ]);

            if ($response->failed()) {
                $verdict = $this->interpreter->interpret($response->json('error'));

                Log::warning('Meta dataset resolution failed', [
                    'lead_id'            => (string) $lead->getKey(),
                    'ad_id'              => $adId,
                    'http_status'        => $response->status(),
                    'category'           => $verdict['category'],
                    'missing_permission' => $verdict['missing_permission'],
                    'required_action'    => $verdict['required_action'],
                    'meta_code'          => $verdict['code'],
                    'meta_message'       => $verdict['message'],
                    'fbtrace_id'         => $verdict['fbtrace_id'],
                ]);

                return null;
            }

            $pixelId = $response->json('adset.promoted_object.pixel_id');

            return blank($pixelId) ? null : (string) $pixelId;
        } catch (Throwable $exception) {
            Log::warning('Meta dataset resolution errored', [
                'lead_id' => (string) $lead->getKey(),
                'ad_id'   => $adId,
                'error'   => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function cacheKey(string $adId): string
    {
        return "lead-pipeline:meta:dataset:ad:{$adId}";
    }

    private function cacheTtl(): int
    {
        return (int) config('lead-pipeline.meta.conversions.cache_ttl', 86400);
    }

    private function graphVersion(): string
    {
        return (string) config('lead-pipeline.meta.conversions.graph_version', 'v21.0');
    }
}
