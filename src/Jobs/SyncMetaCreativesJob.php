<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\MetaAdCreative;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Support\LeadActionSum;

class SyncMetaCreativesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @param list<string>|null $campaignIds */
    public function __construct(
        public string $connectionUuid,
        public string $adAccountId,
        public ?array $campaignIds = null,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("meta-creatives:{$this->adAccountId}"))->releaseAfter(300)];
    }

    public function handle(FacebookGraphService $graph): void
    {
        $connection = FacebookConnection::query()->find($this->connectionUuid);

        if (null === $connection || ! $connection->isConnected()) {
            return;
        }

        $disk   = config('lead-pipeline.reports.media_disk', 'public');
        $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

        $ads = $graph->getAdsWithCreatives($this->adAccountId, $connection->access_token, $this->campaignIds);

        // permanent_url-Bevorzugung (Spec §5): image_hashes sammeln, EIN adimages-Call pro Konto
        $hashes = collect($ads)
            ->map(fn (array $ad): ?string => $ad['creative']['image_hash'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $permanentUrls = $graph->getAdImagePermanentUrls($this->adAccountId, $connection->access_token, $hashes);

        foreach ($ads as $ad) {
            $hash     = $ad['creative']['image_hash'] ?? null;
            $imageUrl = (null !== $hash ? ($permanentUrls[$hash] ?? null) : null)
                ?? $ad['creative']['image_url']
                ?? $ad['creative']['thumbnail_url']
                ?? null;
            $imagePath = null;

            if (null !== $imageUrl) {
                $imagePath = "lead-reports/creatives/{$this->adAccountId}/{$ad['id']}.jpg";
                $response  = Http::timeout(20)->get($imageUrl);

                if ($response->successful()) {
                    Storage::disk($disk)->put($imagePath, $response->body());
                } else {
                    $imagePath = null;
                }
            }

            $insights = $ad['insights']['data'][0] ?? [];

            MetaAdCreative::query()->updateOrCreate(
                ['ad_id' => $ad['id']],
                array_filter([
                    $teamFk                => $connection->{$teamFk},
                    'ad_account_id'        => $this->adAccountId,
                    'campaign_id'          => $ad['campaign_id'] ?? null,
                    'name'                 => $ad['name'] ?? null,
                    'status'               => $ad['status'] ?? null,
                    'image_path'           => $imagePath,
                    'lifetime_impressions' => (int) ($insights['impressions'] ?? 0),
                    'lifetime_spend'       => (string) ($insights['spend'] ?? '0'),
                    'lifetime_leads'       => LeadActionSum::fromActions($insights['actions'] ?? null),
                    'last_synced_at'       => now(),
                ], fn (mixed $value): bool => null !== $value),
            );
        }
    }
}
