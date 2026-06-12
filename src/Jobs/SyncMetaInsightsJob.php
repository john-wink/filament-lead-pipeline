<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadReportAdSource;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;
use JohnWink\FilamentLeadPipeline\Models\MetaReachRange;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Support\LeadActionSum;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;
use Throwable;

class SyncMetaInsightsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const array RATE_LIMIT_CODES = [4, 17, 32, 80000, 80004];

    public int $tries = 3;

    /** @param list<string>|null $campaignIds */
    public function __construct(
        public string $connectionUuid,
        public string $adAccountId,
        public ?array $campaignIds = null,
        public int $days = 28,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("meta-insights:{$this->adAccountId}"))->releaseAfter(300)];
    }

    public function handle(FacebookGraphService $graph): void
    {
        $connection = FacebookConnection::query()->find($this->connectionUuid);

        if (null === $connection || ! $connection->isConnected()) {
            return;
        }

        $timeRange = [
            'since' => CarbonImmutable::now()->subDays($this->days)->toDateString(),
            'until' => CarbonImmutable::now()->toDateString(),
        ];

        try {
            if ($this->importDailyRows($graph, $connection, $timeRange, breakdown: null)) {
                return; // proaktiv re-released (Usage-Header ≥ 80 %, Spec §12)
            }

            if ($this->importDailyRows($graph, $connection, $timeRange, breakdown: 'gender')) {
                return;
            }

            $this->refreshReachPresets($graph, $connection);
        } catch (RequestException $exception) {
            $code = (int) $exception->response->json('error.code', 0);

            if (in_array($code, self::RATE_LIMIT_CODES, true)) {
                $this->release(60 * (2 ** max(0, $this->attempts() - 1)) + random_int(5, 30));

                return;
            }

            $this->markAdSources(['sync_status' => 'failed']);

            throw $exception;
        }

        $this->markAdSources(['sync_status' => 'ok', 'last_synced_at' => now()]);
    }

    public function failed(?Throwable $exception = null): void
    {
        $this->markAdSources(['sync_status' => 'failed']);
    }

    /** @param array<string, mixed> $attributes */
    private function markAdSources(array $attributes): void
    {
        if ( ! Schema::hasTable('lead_report_ad_sources')) {
            return;
        }

        LeadReportAdSource::query()->where('ad_account_id', $this->adAccountId)->update($attributes);
    }

    /**
     * @param  array{since: string, until: string}  $timeRange
     * @return bool true, wenn der Job wegen Usage-Header-Auslastung (≥ 80 %) re-released wurde
     */
    private function importDailyRows(
        FacebookGraphService $graph,
        FacebookConnection $connection,
        array $timeRange,
        ?string $breakdown,
    ): bool {
        $after  = null;
        $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

        do {
            $result = $graph->getAdAccountInsights(
                $this->adAccountId,
                $connection->access_token,
                $timeRange,
                $breakdown,
                $this->campaignIds,
                $after,
            );

            $rows = array_map(fn (array $row): array => [
                'uuid'            => Str::uuid7()->toString(),
                $teamFk           => $connection->{$teamFk},
                'ad_account_id'   => $this->adAccountId,
                'campaign_id'     => $row['campaign_id'] ?? null,
                'campaign_name'   => $row['campaign_name'] ?? null,
                'date'            => $row['date_start'],
                'breakdown_type'  => $breakdown ?? 'none',
                'breakdown_value' => null === $breakdown ? '' : ($row[$breakdown] ?? 'unknown'),
                'impressions'     => (int) ($row['impressions'] ?? 0),
                'reach'           => (int) ($row['reach'] ?? 0),
                'spend'           => (string) ($row['spend'] ?? '0'),
                'clicks'          => (int) ($row['clicks'] ?? 0),
                'link_clicks'     => (int) ($row['inline_link_clicks'] ?? 0),
                'leads'           => LeadActionSum::fromActions($row['actions'] ?? null),
                'created_at'      => now(),
                'updated_at'      => now(),
            ], $result['data']);

            if ([] !== $rows) {
                MetaInsightSnapshot::query()->upsert(
                    $rows,
                    ['ad_account_id', 'campaign_id', 'date', 'breakdown_type', 'breakdown_value'],
                    ['campaign_name', 'impressions', 'reach', 'spend', 'clicks', 'link_clicks', 'leads', 'updated_at'],
                );
            }

            // Proaktives Header-Monitoring (Spec §12): ab 80 % Auslastung verschieben statt weiterzufeuern
            if (($result['usage_pct'] ?? 0) >= 80) {
                $this->release(900);

                return true;
            }

            $after = $result['paging']['cursors']['after'] ?? null;
        } while (null !== $after);

        return false;
    }

    private function refreshReachPresets(FacebookGraphService $graph, FacebookConnection $connection): void
    {
        foreach (ReportDatePresetEnum::cases() as $preset) {
            if (ReportDatePresetEnum::Custom === $preset) {
                continue;
            }

            $range = ReportDateRange::fromPreset($preset)->clampForMetaApi();

            $reach = $graph->getAdAccountReach($this->adAccountId, $connection->access_token, [
                'since' => $range->from->toDateString(),
                'until' => $range->till->toDateString(),
            ], $this->campaignIds);

            MetaReachRange::query()->updateOrCreate(
                [
                    'ad_account_id' => $this->adAccountId,
                    'campaign_key'  => MetaReachRange::campaignKey($this->campaignIds),
                    'preset'        => $preset->value,
                ],
                [
                    'date_from'  => $range->from->toDateString(),
                    'date_till'  => $range->till->toDateString(),
                    'reach'      => $reach,
                    'fetched_at' => now(),
                ],
            );
        }
    }
}
