<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadTransferred;
use JohnWink\FilamentLeadPipeline\Exceptions\LeadAlreadyTransferredException;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class LeadTransferService
{
    public function transfer(
        Lead $lead,
        LeadBoard $targetBoard,
        ?LeadPhase $targetPhase = null,
        ?string $assigneeId = null,
        ?string $note = null,
    ): Lead {
        $originBoard = $lead->board;

        if ($originBoard && $originBoard->getKey() === $targetBoard->getKey()) {
            throw new InvalidArgumentException('Cannot transfer a lead to its current board.');
        }

        $alreadyTransferred = Lead::query()
            ->where('external_id', $lead->getKey())
            ->where(Lead::fkColumn('lead_board'), $targetBoard->getKey())
            ->exists();

        if ($alreadyTransferred) {
            throw new LeadAlreadyTransferredException($lead, $targetBoard);
        }

        return DB::transaction(function () use ($lead, $originBoard, $targetBoard, $targetPhase, $assigneeId, $note): Lead {
            $source = $this->resolveOrCreateTransferSource($targetBoard, $originBoard);
            $phase  = $targetPhase ?? $this->firstNonTerminalPhase($targetBoard);

            $new = Lead::create([
                Lead::fkColumn('lead_board')  => $targetBoard->getKey(),
                Lead::fkColumn('lead_phase')  => $phase?->getKey(),
                Lead::fkColumn('lead_source') => $source->getKey(),
                'external_id'                 => $lead->getKey(),
                'name'                        => $lead->name,
                'email'                       => $lead->email,
                'phone'                       => $lead->phone,
                'value'                       => $lead->value,
                'status'                      => LeadStatusEnum::Active,
                'assigned_to'                 => $assigneeId ?? $source->default_assigned_to,
            ]);

            $this->mapFieldValues($lead, $new, $targetBoard);

            $new->activities()->create([
                'type'        => LeadActivityTypeEnum::Transferred->value,
                'description' => sprintf(
                    __('lead-pipeline::lead-pipeline.activity.transferred_from'),
                    $originBoard?->name ?? '—',
                ),
                'properties'  => ['from_board' => $originBoard?->getKey(), 'origin_lead' => $lead->getKey(), 'note' => $note],
                'causer_type' => config('lead-pipeline.user_model'),
                'causer_id'   => auth()->id(),
            ]);

            $lead->activities()->create([
                'type'        => LeadActivityTypeEnum::Transferred->value,
                'description' => sprintf(
                    __('lead-pipeline::lead-pipeline.activity.transferred_to'),
                    $targetBoard->name,
                ),
                'properties'  => ['to_board' => $targetBoard->getKey(), 'new_lead' => $new->getKey(), 'note' => $note],
                'causer_type' => config('lead-pipeline.user_model'),
                'causer_id'   => auth()->id(),
            ]);

            LeadTransferred::dispatch($lead, $new, $originBoard, $targetBoard, auth()->user());

            return $new->refresh();
        });
    }

    private function resolveOrCreateTransferSource(LeadBoard $targetBoard, ?LeadBoard $originBoard): LeadSource
    {
        $originKey = $originBoard?->getKey();

        $existing = $targetBoard->sources()
            ->where('driver', LeadSourceTypeEnum::InternalTransfer->value)
            ->get()
            ->first(fn (LeadSource $s): bool => ($s->config['origin_board'] ?? null) === $originKey);

        if ($existing) {
            return $existing;
        }

        return $targetBoard->sources()->create([
            'name' => sprintf(
                __('lead-pipeline::lead-pipeline.transfer.source_name'),
                $originBoard?->name ?? '—',
            ),
            'driver'    => LeadSourceTypeEnum::InternalTransfer->value,
            'status'    => LeadSourceStatusEnum::Active,
            'config'    => ['origin_board' => $originKey],
            'team_uuid' => $targetBoard->team_uuid,
        ]);
    }

    private function firstNonTerminalPhase(LeadBoard $board): ?LeadPhase
    {
        return $board->phases()
            ->whereIn('type', [LeadPhaseTypeEnum::Open, LeadPhaseTypeEnum::InProgress])
            ->ordered()
            ->first();
    }

    private function mapFieldValues(Lead $origin, Lead $new, LeadBoard $targetBoard): void
    {
        $origin->loadMissing('fieldValues.definition');
        $targetDefs = $targetBoard->fieldDefinitions()->get()->keyBy('key');

        foreach ($origin->fieldValues as $value) {
            $key = $value->definition?->key;

            if (blank($key) || in_array($key, ['name', 'email', 'phone'], true)) {
                continue;
            }

            $targetDef = $targetDefs->get($key);
            if ( ! $targetDef) {
                continue;
            }

            $new->setFieldValue($targetDef, $value->casted_value);
        }
    }
}
