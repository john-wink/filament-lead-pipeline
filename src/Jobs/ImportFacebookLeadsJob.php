<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
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
        public bool $updateExisting = false,
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
                } catch (FacebookTransientException) {
                    $this->release(60);

                    return;
                } catch (FacebookTokenInvalidException $e) {
                    $connection = $page->connection;
                    if ($connection) {
                        $connection->forceFill([
                            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
                            'last_error' => Str::limit($e->getMessage(), 1000),
                        ])->save();
                        FacebookConnectionNeedsReauth::dispatch($connection, $e->getMessage());
                    }
                    $source->update([
                        'status'        => LeadSourceStatusEnum::Error,
                        'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
                    ]);

                    return;
                } catch (Exception $e) {
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

                    // Build field data indexed by both the raw name and its slugified
                    // form so lookups work regardless of whether the mapping stores
                    // the Facebook question key (slug) or the display label.
                    $fieldData = [];
                    foreach ($fbLead['field_data'] ?? [] as $f) {
                        $value            = $f['values'][0] ?? null;
                        $name             = $f['name'] ?? '';
                        $fieldData[$name] = $value;
                        $slug             = Str::slug($name, '_');
                        if ($slug !== $name && ! isset($fieldData[$slug])) {
                            $fieldData[$slug] = $value;
                        }
                    }

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

                    // Check if lead already exists (by Facebook Lead ID or email)
                    $existingLead = null;
                    if ($fbLeadId) {
                        $existingLead = Lead::query()
                            ->where(Lead::fkColumn('lead_board'), $board->getKey())
                            ->where('external_id', $fbLeadId)
                            ->first();
                    }
                    if ( ! $existingLead && $email) {
                        $existingLead = Lead::query()
                            ->where(Lead::fkColumn('lead_board'), $board->getKey())
                            ->where('email', $email)
                            ->first();
                    }

                    // Update existing lead (re-import mode)
                    if ($existingLead) {
                        if ($this->updateExisting) {
                            $updates = [
                                'external_id'          => $fbLeadId,
                                'raw_data'             => $fbLead,
                                'source_campaign_id'   => $this->attribution($fbLead, 'campaign_id'),
                                'source_campaign_name' => $this->attribution($fbLead, 'campaign_name'),
                                'source_adgroup_id'    => $this->attribution($fbLead, 'adset_id', 'adgroup_id'),
                                'source_adgroup_name'  => $this->attribution($fbLead, 'adset_name', 'adgroup_name'),
                                'source_ad_id'         => $this->attribution($fbLead, 'ad_id'),
                                'source_ad_name'       => $this->attribution($fbLead, 'ad_name'),
                                'source_channel'       => $this->attribution($fbLead, 'platform'),
                            ];

                            if ($name && '' !== $name && ( ! $existingLead->name || '' === $existingLead->name || 'Unknown' === $existingLead->name)) {
                                $updates['name'] = (string) $name;
                            }
                            // Always update name if we have a better one (first+last vs empty)
                            if ($name && '' !== $name && $existingLead->name !== $name) {
                                $updates['name'] = (string) $name;
                            }
                            if ($email && ! $existingLead->email) {
                                $updates['email'] = $email;
                            }
                            if ($phone && ! $existingLead->phone) {
                                $updates['phone'] = $phone;
                            }

                            $existingLead->update($updates);

                            // Update custom field mappings
                            foreach ($customMapping as $item) {
                                $fbKey    = $item['facebook_key'] ?? '';
                                $boardKey = $item['board_field_key'] ?? '__ignore__';

                                if ('__ignore__' === $boardKey || '__create__' === $boardKey || ! isset($fieldData[$fbKey])) {
                                    continue;
                                }

                                $definition = $fieldDefinitions->firstWhere('key', $boardKey);
                                if ($definition) {
                                    $existingLead->setFieldValue($definition, $fieldData[$fbKey]);
                                }
                            }
                        }

                        continue;
                    }

                    // Create new lead
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
                    $lead->source_campaign_id              = $this->attribution($fbLead, 'campaign_id');
                    $lead->source_campaign_name            = $this->attribution($fbLead, 'campaign_name');
                    $lead->source_adgroup_id               = $this->attribution($fbLead, 'adset_id', 'adgroup_id');
                    $lead->source_adgroup_name             = $this->attribution($fbLead, 'adset_name', 'adgroup_name');
                    $lead->source_ad_id                    = $this->attribution($fbLead, 'ad_id');
                    $lead->source_ad_name                  = $this->attribution($fbLead, 'ad_name');
                    $lead->source_channel                  = $this->attribution($fbLead, 'platform');
                    $lead->external_id                     = $fbLeadId;
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

    /**
     * @param  array<string, mixed>  $fbLead
     */
    private function attribution(array $fbLead, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $fbLead[$key] ?? null;
            if (null !== $value && '' !== $value) {
                return (string) $value;
            }
        }

        return null;
    }
}
