<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Exceptions\ImmoScoutAuthException;
use JohnWink\FilamentLeadPipeline\Exceptions\ImmoScoutTransientException;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;
use JohnWink\FilamentLeadPipeline\Support\ImmoScoutLeadMapper;

/**
 * Pulls ImmoScout24 construction financing leads for one source.
 *
 * Without an explicit $days window the job polls incrementally: from the last
 * successful receive minus one day of overlap (the API has no pagination, so
 * overlapping windows plus external_id dedup guarantee completeness).
 */
class ImportImmoScoutLeadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(
        public LeadSource $source,
        public ?int $days = null,
        public bool $testMode = false,
    ) {}

    public function handle(ImmoScoutApiService $api): void
    {
        $source = $this->source;
        $board  = $source->board;

        $connection = ImmoScoutConnection::query()
            ->find($source->config['immoscout_connection_uuid'] ?? null);

        if ( ! $connection || ! $board) {
            $source->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => 'ImmoScout24-Verbindung nicht gefunden.',
            ]);

            return;
        }

        $targetPhase = $this->resolveTargetPhase($source);

        if ( ! $targetPhase) {
            return;
        }

        try {
            $leads = $this->fetchLeads($api, $connection);
        } catch (ImmoScoutTransientException) {
            $this->release(60);

            return;
        } catch (ImmoScoutAuthException $e) {
            $connection->update([
                'status'     => ImmoScoutConnectionStatusEnum::Error,
                'last_error' => $e->getMessage(),
            ]);
            $source->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => $e->getMessage(),
            ]);

            return;
        } catch (Exception $e) {
            $source->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $mapper          = new ImmoScoutLeadMapper();
        $defaultAssignee = $source->default_assigned_to;

        foreach ($leads as $rawLead) {
            $externalId = isset($rawLead['id']) ? (string) $rawLead['id'] : null;

            if (null === $externalId) {
                continue;
            }

            $exists = Lead::query()
                ->where(Lead::fkColumn('lead_board'), $board->getKey())
                ->where('external_id', $externalId)
                ->exists();

            if ($exists) {
                continue;
            }

            $data = $mapper->map($rawLead);

            $lead                                  = new Lead();
            $lead->{Lead::fkColumn('lead_board')}  = $board->getKey();
            $lead->{Lead::fkColumn('lead_phase')}  = $targetPhase->getKey();
            $lead->{Lead::fkColumn('lead_source')} = $source->getKey();
            $lead->name                            = $data->name;
            $lead->email                           = $data->email;
            $lead->phone                           = $data->phone;
            $lead->value                           = $data->value;
            $lead->status                          = LeadStatusEnum::Active;
            $lead->sort                            = Lead::nextSortForPhase($targetPhase->getKey());
            $lead->assigned_to                     = filled($defaultAssignee) ? $defaultAssignee : null;
            $lead->raw_data                        = $rawLead;
            $lead->source_channel                  = 'immoscout24';
            $lead->external_id                     = $externalId;

            try {
                $lead->save();
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            foreach ($data->custom_fields as $key => $value) {
                $definition = ImmoScoutLeadMapper::resolveDefinition($board, $key);

                if ($definition) {
                    $lead->setFieldValue($definition, $value);
                }
            }

            $lead->activities()->create([
                'type'        => LeadActivityTypeEnum::Created->value,
                'description' => sprintf('Lead importiert via ImmoScout24 (%s)', $source->name),
                'properties'  => ['source_driver' => 'immoscout24', 'source_name' => $source->name, 'imported' => true],
            ]);

            LeadCreated::dispatch($lead);
        }

        $source->update([
            'last_received_at' => now(),
            'status'           => LeadSourceStatusEnum::Active,
            'error_message'    => null,
        ]);

        $connection->update([
            'last_synced_at' => now(),
            'status'         => ImmoScoutConnectionStatusEnum::Connected,
            'last_error'     => null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function fetchLeads(ImmoScoutApiService $api, ImmoScoutConnection $connection): array
    {
        if ($this->testMode) {
            return $api->fetchTestLeads($connection);
        }

        $from = null !== $this->days
            ? now()->subDays($this->days)
            : ($this->source->last_received_at?->copy()->subDay() ?? now()->subDays(90));

        return $api->fetchLeads($connection, $from, now());
    }

    private function resolveTargetPhase(LeadSource $source): ?object
    {
        $board = $source->board;

        if (filled($source->default_assigned_to)) {
            $phase = $board->phases()
                ->where('type', LeadPhaseTypeEnum::InProgress)
                ->ordered()
                ->first();

            if ($phase) {
                return $phase;
            }
        }

        return $board->phases()
            ->where('type', LeadPhaseTypeEnum::Open)
            ->ordered()
            ->first();
    }
}
