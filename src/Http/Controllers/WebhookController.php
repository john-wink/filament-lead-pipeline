<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadAssigned;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Events\LeadReceived;
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

            $maxSort = Lead::query()
                ->where(Lead::fkColumn('lead_phase'), $targetPhase->getKey())
                ->max('sort') ?? 0;

            $lead                                  = new Lead();
            $lead->{Lead::fkColumn('lead_board')}  = $board->getKey();
            $lead->{Lead::fkColumn('lead_phase')}  = $targetPhase->getKey();
            $lead->{Lead::fkColumn('lead_source')} = $source->getKey();
            $lead->name                            = $leadData->name;
            $lead->email                           = $leadData->email;
            $lead->phone                           = $leadData->phone;
            $lead->value                           = $leadData->value;
            $lead->status                          = LeadStatusEnum::Active;
            $lead->sort                            = $maxSort + 1;
            $lead->assigned_to                     = $hasAssignee ? $defaultAssignee : null;
            $lead->raw_data                        = $request->all();
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
                    ->where('status', LeadSourceStatusEnum::Active)
                    ->get()
                    ->filter(fn ($source) => in_array($formId, $source->facebook_form_ids ?? [], true));

                if ($sources->isEmpty()) {
                    continue;
                }

                $facebook = app(FacebookGraphService::class);
                $leadData = $facebook->getLeadData($leadgenId, $fbPage->page_access_token);

                $fieldData = collect($leadData['field_data'] ?? [])
                    ->mapWithKeys(fn ($field) => [$field['name'] => $field['values'][0] ?? null])
                    ->toArray();

                foreach ($sources as $source) {
                    try {
                        $lead           = $this->createLeadFromFacebookData($source, $fieldData, $leadData);
                        $leadsCreated[] = $lead->getKey();
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
        $board         = $source->board;
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

        $name      = $findFirst($mapping['name'] ?? ['full_name']);
        $firstName = $findFirst($mapping['first_name'] ?? []);
        $lastName  = $findFirst($mapping['last_name'] ?? []);
        $email     = $findFirst($mapping['email'] ?? ['email']);
        $phone     = $findFirst($mapping['phone'] ?? ['phone_number']);

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

        $maxSort = Lead::query()
            ->where(Lead::fkColumn('lead_phase'), $targetPhase->getKey())
            ->max('sort') ?? 0;

        $lead                                  = new Lead();
        $lead->{Lead::fkColumn('lead_board')}  = $board->getKey();
        $lead->{Lead::fkColumn('lead_phase')}  = $targetPhase->getKey();
        $lead->{Lead::fkColumn('lead_source')} = $source->getKey();
        $lead->name                            = (string) ($name ?? '');
        $lead->email                           = $email;
        $lead->phone                           = $phone;
        $lead->status                          = LeadStatusEnum::Active;
        $lead->sort                            = $maxSort + 1;
        $lead->assigned_to                     = $hasAssignee ? $defaultAssignee : null;
        $lead->raw_data                        = $rawData ?: null;
        $lead->save();

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

        if ($hasAssignee) {
            $assignedUser = config('lead-pipeline.user_model')::find($defaultAssignee);
            LeadAssigned::dispatch($lead, $assignedUser, null);
        }

        return $lead;
    }
}
