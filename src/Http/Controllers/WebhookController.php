<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Events\LeadReceived;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\LeadSourceManager;
use RuntimeException;

class WebhookController
{
    public function __construct(
        public LeadSourceManager $manager,
    ) {}

    public function handle(Request $request, string $sourceId): JsonResponse
    {
        $source = LeadSource::query()
            ->where(LeadSource::pkColumn(), $sourceId)
            ->where('status', LeadSourceStatusEnum::Active)
            ->first();

        if ( ! $source) {
            return response()->json(['error' => 'Source not found or inactive.'], 404);
        }

        try {
            $driver = $this->manager->getDriver($source->driver);

            $signature      = $request->header('X-Hub-Signature-256', '');
            $bearerToken    = $request->bearerToken();
            $signatureValue = '' !== $signature && null !== $signature ? $signature : ($bearerToken ?? '');

            if ( ! $driver->verifySignature($request->getContent(), $signatureValue, $source)) {
                return response()->json(['error' => 'Invalid signature.'], 403);
            }

            LeadReceived::dispatch($source, $request->all());

            $payload = new WebhookPayloadData(
                driver: $source->driver,
                source_id: $sourceId,
                raw_payload: $request->all(),
                signature: '' !== $signatureValue ? $signatureValue : null,
            );

            $leadData = $driver->processWebhook($payload, $source);

            $board           = $source->board;
            $defaultAssignee = $source->default_assigned_to;
            $hasAssignee     = filled($defaultAssignee);

            if ($hasAssignee) {
                $targetPhase = $board->phases()
                    ->where('type', LeadPhaseTypeEnum::InProgress)
                    ->ordered()
                    ->first();
            }

            if ( ! isset($targetPhase) || ! $targetPhase) {
                $targetPhase = $board->phases()
                    ->where('type', LeadPhaseTypeEnum::Open)
                    ->ordered()
                    ->first();
            }

            if ( ! $targetPhase) {
                return response()->json(['error' => 'No suitable phase found on board.'], 422);
            }

            $lead                                  = new Lead();
            $lead->{Lead::fkColumn('lead_board')}  = $board->getKey();
            $lead->{Lead::fkColumn('lead_phase')}  = $targetPhase->getKey();
            $lead->{Lead::fkColumn('lead_source')} = $source->getKey();
            $lead->name                            = $leadData->name;
            $lead->email                           = $leadData->email;
            $lead->phone                           = $leadData->phone;
            $lead->value                           = $leadData->value;
            $lead->status                          = LeadStatusEnum::Active;
            $lead->sort                            = Lead::nextSortForPhase($targetPhase->getKey());
            $lead->assigned_to                     = $hasAssignee ? $defaultAssignee : null;
            $lead->raw_data                        = $request->all();
            $lead->source_campaign_id              = $leadData->source_campaign_id;
            $lead->source_campaign_name            = $leadData->source_campaign_name;
            $lead->source_adgroup_id               = $leadData->source_adgroup_id;
            $lead->source_adgroup_name             = $leadData->source_adgroup_name;
            $lead->source_ad_id                    = $leadData->source_ad_id;
            $lead->source_ad_name                  = $leadData->source_ad_name;
            $lead->source_channel                  = $leadData->source_channel;
            $lead->save();

            $fieldDefinitions = $board->fieldDefinitions()->get();

            foreach ($leadData->custom_fields as $key => $value) {
                $definition = $fieldDefinitions->firstWhere('key', $key);

                if ($definition) {
                    $lead->setFieldValue($definition, $value);
                }
            }

            $lead->activities()->create([
                'type'        => LeadActivityTypeEnum::Created->value,
                'description' => sprintf('Lead created via %s Webhook', $source->name),
                'properties'  => [
                    'source_driver' => $source->driver,
                    'source_name'   => $source->name,
                ],
            ]);

            $source->update(['last_received_at' => now()]);

            LeadCreated::dispatch($lead);

            return response()->json(['id' => $lead->getKey()], 201);
        } catch (Exception $e) {
            $source->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Internal server error.'], 500);
        }
    }

    public function verifyMeta(Request $request, string $sourceId): Response
    {
        $source = LeadSource::query()
            ->where(LeadSource::pkColumn(), $sourceId)
            ->first();

        if ( ! $source) {
            return response('Source not found.', 404);
        }

        $hubVerifyToken = $request->query('hub_verify_token', '');
        $hubChallenge   = $request->query('hub_challenge', '');

        $expectedToken = $source->config['verify_token'] ?? '';

        if ('' === $hubVerifyToken || $hubVerifyToken !== $expectedToken) {
            return response('Invalid verify token.', 403);
        }

        return response((string) $hubChallenge, 200);
    }

    public function handleMetaCentral(Request $request): JsonResponse
    {
        $appSecret = config('lead-pipeline.facebook.client_secret');
        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        if ( ! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        $entries      = $request->input('entry', []);
        $leadsCreated = [];

        foreach ($entries as $entry) {
            $pageId  = $entry['id'] ?? null;
            $changes = $entry['changes'] ?? [];

            if ( ! $pageId) {
                continue;
            }

            $fbPage = FacebookPage::query()->where('page_id', $pageId)->first();

            if ( ! $fbPage) {
                continue;
            }

            foreach ($changes as $change) {
                if ('leadgen' !== ($change['field'] ?? '')) {
                    continue;
                }

                $leadgenId = $change['value']['leadgen_id'] ?? null;
                $formId    = $change['value']['form_id'] ?? null;

                if ( ! $leadgenId || ! $formId) {
                    continue;
                }

                $sources = LeadSource::query()
                    ->where('facebook_page_uuid', $fbPage->uuid)
                    ->where('status', '!=', LeadSourceStatusEnum::Paused)
                    ->get()
                    ->filter(fn ($source) => in_array($formId, $source->facebook_form_ids ?? [], true));

                if ($sources->isEmpty()) {
                    continue;
                }

                $facebook = app(FacebookGraphService::class);

                try {
                    $leadData = $facebook->getLeadData($leadgenId, $fbPage->page_access_token);
                } catch (FacebookTokenInvalidException $e) {
                    $connection = $fbPage->connection;
                    if ($connection) {
                        $this->markConnectionNeedsReauth($connection, $e->getMessage());
                    }

                    // ACK (200 at the end) so Facebook stops retrying — prevents the 36h
                    // retry cascade + webhook subscription disablement. Missed leads are
                    // backfilled via ImportFacebookLeadsJob after reconnect.
                    continue;
                }

                $fieldData = [];
                foreach ($leadData['field_data'] ?? [] as $field) {
                    $value            = $field['values'][0] ?? null;
                    $name             = $field['name'] ?? '';
                    $fieldData[$name] = $value;
                    $slug             = Str::slug($name, '_');
                    if ($slug !== $name && ! isset($fieldData[$slug])) {
                        $fieldData[$slug] = $value;
                    }
                }

                foreach ($sources as $source) {
                    try {
                        $lead = $this->createLeadFromFacebookData($source, $fieldData, $leadData);
                        if ($lead->wasRecentlyCreated) {
                            $leadsCreated[] = $lead->getKey();
                        }
                    } catch (Exception $e) {
                        $source->update([
                            'status'        => LeadSourceStatusEnum::Error,
                            'error_message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response()->json(['leads_created' => count($leadsCreated)]);
    }

    public function verifyMetaCentral(Request $request): Response
    {
        $verifyToken  = config('lead-pipeline.facebook.verify_token');
        $hubToken     = $request->query('hub_verify_token', '');
        $hubChallenge = $request->query('hub_challenge', '');

        if ('' === $hubToken || $hubToken !== $verifyToken) {
            return response('Invalid verify token.', 403);
        }

        return response((string) $hubChallenge, 200);
    }

    /**
     * @param  array<string, mixed>  $fieldData
     * @param  array<string, mixed>  $rawData
     */
    private function createLeadFromFacebookData(LeadSource $source, array $fieldData, array $rawData = []): Lead
    {
        $board      = $source->board;
        $externalId = $rawData['id'] ?? null;

        if ($externalId) {
            $existing = Lead::query()
                ->where(Lead::fkColumn('lead_source'), $source->getKey())
                ->where('external_id', $externalId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $config        = $source->config ?? [];
        $mapping       = $config['field_mapping'] ?? [];
        $customMapping = $config['custom_field_mapping'] ?? [];

        // Resolve fields from mapping (arrays of possible keys)
        $findFirst = function (array $keys) use ($fieldData): ?string {
            foreach ($keys as $key) {
                if (isset($fieldData[$key]) && '' !== $fieldData[$key]) {
                    return (string) $fieldData[$key];
                }
            }

            return null;
        };

        $name      = $findFirst($mapping['name'] ?? ['full_name', 'vollständiger_name']);
        $firstName = $findFirst($mapping['first_name'] ?? ['first_name', 'vorname']);
        $lastName  = $findFirst($mapping['last_name'] ?? ['last_name', 'nachname']);
        $email     = $findFirst($mapping['email'] ?? ['email', 'e-mail-adresse', 'e-mail']);
        $phone     = $findFirst($mapping['phone'] ?? ['phone_number', 'telefonnummer', 'phone']);

        if (( ! $name || '' === $name) && ($firstName || $lastName)) {
            $name = mb_trim("{$firstName} {$lastName}");
        }

        $defaultAssignee = $source->default_assigned_to;
        $hasAssignee     = filled($defaultAssignee);

        if ($hasAssignee) {
            $targetPhase = $board->phases()
                ->where('type', LeadPhaseTypeEnum::InProgress)
                ->ordered()
                ->first();
        }

        if ( ! isset($targetPhase) || ! $targetPhase) {
            $targetPhase = $board->phases()
                ->where('type', LeadPhaseTypeEnum::Open)
                ->ordered()
                ->first();
        }

        if ( ! $targetPhase) {
            throw new RuntimeException('No suitable phase found on board.');
        }

        $lead                                  = new Lead();
        $lead->{Lead::fkColumn('lead_board')}  = $board->getKey();
        $lead->{Lead::fkColumn('lead_phase')}  = $targetPhase->getKey();
        $lead->{Lead::fkColumn('lead_source')} = $source->getKey();
        $lead->name                            = (string) ($name ?? '');
        $lead->email                           = $email;
        $lead->phone                           = $phone;
        $lead->status                          = LeadStatusEnum::Active;
        $lead->sort                            = Lead::nextSortForPhase($targetPhase->getKey());
        $lead->assigned_to                     = $hasAssignee ? $defaultAssignee : null;
        $lead->raw_data                        = $rawData ?: null;
        $lead->source_campaign_id              = $this->attributionValue($rawData, 'campaign_id');
        $lead->source_campaign_name            = $this->attributionValue($rawData, 'campaign_name');
        $lead->source_adgroup_id               = $this->attributionValue($rawData, 'adset_id', 'adgroup_id');
        $lead->source_adgroup_name             = $this->attributionValue($rawData, 'adset_name', 'adgroup_name');
        $lead->source_ad_id                    = $this->attributionValue($rawData, 'ad_id');
        $lead->source_ad_name                  = $this->attributionValue($rawData, 'ad_name');
        $lead->source_channel                  = $this->attributionValue($rawData, 'platform');
        $lead->external_id                     = $externalId;

        try {
            $lead->save();
        } catch (UniqueConstraintViolationException) {
            return Lead::query()
                ->where(Lead::fkColumn('lead_source'), $source->getKey())
                ->where('external_id', $externalId)
                ->first() ?? $lead;
        }

        // Apply custom field mapping
        $fieldDefinitions = $board->fieldDefinitions()->get();

        foreach ($customMapping as $item) {
            $fbKey    = $item['facebook_key'] ?? '';
            $boardKey = $item['board_field_key'] ?? '__ignore__';

            if ('__ignore__' === $boardKey || '__create__' === $boardKey || ! isset($fieldData[$fbKey])) {
                continue;
            }

            $definition = $fieldDefinitions->firstWhere('key', $boardKey);
            if ($definition) {
                $lead->setFieldValue($definition, $fieldData[$fbKey]);
            }
        }

        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Created->value,
            'description' => sprintf('Lead created via Facebook (%s)', $source->name),
            'properties'  => [
                'source_driver' => 'meta',
                'source_name'   => $source->name,
            ],
        ]);

        $source->update(['last_received_at' => now()]);

        LeadCreated::dispatch($lead);

        return $lead;
    }

    private function markConnectionNeedsReauth(FacebookConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($reason, 1000),
        ])->save();

        $connection->pages()
            ->whereHas('leadSources')
            ->each(fn (FacebookPage $page) => $page->leadSources()->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
            ]));

        FacebookConnectionNeedsReauth::dispatch($connection, $reason);
    }

    /**
     * @param  array<string, mixed>  $rawData
     */
    private function attributionValue(array $rawData, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $rawData[$key] ?? null;
            if (null !== $value && '' !== $value) {
                return (string) $value;
            }
        }

        return null;
    }
}
