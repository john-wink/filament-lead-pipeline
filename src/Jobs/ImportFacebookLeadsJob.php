<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

class ImportFacebookLeadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(
        public LeadSource $source,
        public int $days = 90,
    ) {}

    public function handle(FacebookGraphService $facebook): void
    {
        $source = $this->source;
        $board  = $source->board;
        $page   = $source->facebookPage;

        if ( ! $page) {
            return;
        }

        $config        = $source->config ?? [];
        $mapping       = $config['field_mapping'] ?? [];
        $customMapping = $config['custom_field_mapping'] ?? [];
        $formIds       = $source->facebook_form_ids ?? [];
        $since         = now()->subDays($this->days)->timestamp;

        $defaultAssignee = $source->default_assigned_to;
        $hasAssignee     = filled($defaultAssignee);

        $targetPhase = null;
        if ($hasAssignee) {
            $targetPhase = $board->phases()
                ->where('type', LeadPhaseTypeEnum::InProgress)
                ->ordered()
                ->first();
        }
        if ( ! $targetPhase) {
            $targetPhase = $board->phases()
                ->where('type', LeadPhaseTypeEnum::Open)
                ->ordered()
                ->first();
        }
        if ( ! $targetPhase) {
            return;
        }

        $fieldDefinitions = $board->fieldDefinitions()->get();

        foreach ($formIds as $formId) {
            $afterCursor = null;

            do {
                try {
                    $result = $facebook->getFormLeads($formId, $page->page_access_token, $since, $afterCursor);
                } catch (Exception $e) {
                    if (str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), '429')) {
                        $this->release(60);

                        return;
                    }
                    $source->update([
                        'status'        => LeadSourceStatusEnum::Error,
                        'error_message' => $e->getMessage(),
                    ]);

                    return;
                }

                $leads       = $result['data'] ?? [];
                $afterCursor = $result['paging']['cursors']['after'] ?? null;
                $hasMore     = isset($result['paging']['next']);

                foreach ($leads as $fbLead) {
                    $fbLeadId = $fbLead['id'] ?? null;

                    // Dedup by Facebook Lead ID (raw_data->id)
                    if ($fbLeadId && Lead::query()
                        ->where(Lead::fkColumn('lead_board'), $board->getKey())
                        ->whereJsonContains('raw_data->id', $fbLeadId)
                        ->exists()) {
                        continue;
                    }

                    $fieldData = collect($fbLead['field_data'] ?? [])
                        ->mapWithKeys(fn ($f) => [$f['name'] => $f['values'][0] ?? null])
                        ->toArray();

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

                    // Additional dedup by email + board
                    if ($email && Lead::query()
                        ->where(Lead::fkColumn('lead_board'), $board->getKey())
                        ->where('email', $email)
                        ->exists()) {
                        continue;
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
                    $lead->raw_data                        = $fbLead;
                    $lead->save();

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
                        'description' => sprintf('Lead importiert via Facebook (%s)', $source->name),
                        'properties'  => ['source_driver' => 'meta', 'source_name' => $source->name, 'imported' => true],
                    ]);

                    LeadCreated::dispatch($lead);
                }
            } while ($hasMore && $afterCursor);
        }

        $source->update(['last_received_at' => now()]);
    }
}
