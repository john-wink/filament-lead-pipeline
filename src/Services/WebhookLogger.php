<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use Throwable;

class WebhookLogger
{
    /** @var array<int, string> */
    private const REDACT_KEYS = [
        'access_token',
        'page_access_token',
        'authorization',
        'token',
        'api_token',
        'webhook_secret',
        'x-hub-signature-256',
    ];

    /** @param array<string, mixed>|null $response */
    public function recordIncoming(
        ?LeadSource $source,
        Request $request,
        string $sourceId,
        string $outcome,
        int $httpStatus,
        ?string $message = null,
        ?Lead $lead = null,
        ?array $response = null,
    ): void {
        $this->write([
            'event_type'       => WebhookLogEventType::Incoming,
            'team_uuid'        => $source?->team_uuid,
            'lead_source_uuid' => $source?->getKey(),
            'driver'           => $source?->driver,
            'lead_uuid'        => $lead?->getKey(),
            'outcome'          => $outcome,
            'http_status'      => $httpStatus,
            'message'          => $message,
            'request'          => ['source_id' => $sourceId, 'payload' => $request->all()],
            'response'         => $response,
        ]);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    public function recordRegistration(string $pageId, array $request, ?array $response, bool $success, ?string $message = null): void
    {
        $page = rescue(fn (): ?FacebookPage => FacebookPage::query()->where('page_id', $pageId)->first(), null, false);

        $this->write([
            'event_type'         => WebhookLogEventType::Registration,
            'team_uuid'          => $page?->connection?->team_uuid,
            'facebook_page_uuid' => $page?->getKey(),
            'page_id'            => $pageId,
            'driver'             => 'meta',
            'outcome'            => $success ? 'subscribed' : 'subscribe_failed',
            'message'            => $message,
            'request'            => $request,
            'response'           => $response,
        ]);
    }

    public function recordVerify(?LeadSource $source, ?string $pageId, bool $ok, ?string $message = null): void
    {
        $this->write([
            'event_type'       => WebhookLogEventType::Verify,
            'team_uuid'        => $source?->team_uuid,
            'lead_source_uuid' => $source?->getKey(),
            'page_id'          => $pageId,
            'driver'           => 'meta',
            'outcome'          => $ok ? 'verified' : 'verify_failed',
            'message'          => $message,
        ]);
    }

    /** @param array<string, mixed>|null $response */
    public function recordStatusCheck(string $pageId, ?array $response, bool $ok, ?string $message = null): void
    {
        $page = rescue(fn (): ?FacebookPage => FacebookPage::query()->where('page_id', $pageId)->first(), null, false);

        $this->write([
            'event_type'         => WebhookLogEventType::StatusCheck,
            'team_uuid'          => $page?->connection?->team_uuid,
            'facebook_page_uuid' => $page?->getKey(),
            'page_id'            => $pageId,
            'driver'             => 'meta',
            'outcome'            => $ok ? 'ok' : 'error',
            'message'            => $message,
            'response'           => $response,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function write(array $attributes): void
    {
        if ( ! config('lead-pipeline.webhooks.logging.enabled', true)) {
            return;
        }

        try {
            if ( ! config('lead-pipeline.webhooks.logging.store_payload', true)) {
                $attributes['request']  = null;
                $attributes['response'] = null;
            } else {
                $attributes['request'] = isset($attributes['request']) && is_array($attributes['request'])
                    ? $this->redact($attributes['request'])
                    : null;
                $attributes['response'] = isset($attributes['response']) && is_array($attributes['response'])
                    ? $this->redact($attributes['response'])
                    : null;
            }

            LeadWebhookLog::create($attributes);

            $eventType = $attributes['event_type'];

            Log::channel(config('lead-pipeline.webhooks.logging.channel', 'lead-webhooks'))->info('webhook', [
                'event_type'  => $eventType instanceof WebhookLogEventType ? $eventType->value : $eventType,
                'outcome'     => $attributes['outcome'] ?? null,
                'http_status' => $attributes['http_status'] ?? null,
                'source'      => $attributes['lead_source_uuid'] ?? null,
                'page_id'     => $attributes['page_id'] ?? null,
                'message'     => $attributes['message'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);

                continue;
            }

            if (in_array(mb_strtolower((string) $key), self::REDACT_KEYS, true)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
