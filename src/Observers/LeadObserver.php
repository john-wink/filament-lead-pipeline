<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Observers;

use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadAssigned;
use JohnWink\FilamentLeadPipeline\Events\LeadMoved;
use JohnWink\FilamentLeadPipeline\Events\LeadStatusChanged;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadConversionService;
use Throwable;

class LeadObserver
{
    public function updated(Lead $lead): void
    {
        $this->handlePhaseChanged($lead);
        $this->handleStatusChanged($lead);
        $this->handleAssignmentChanged($lead);
    }

    private function handlePhaseChanged(Lead $lead): void
    {
        $phaseFk = Lead::fkColumn('lead_phase');

        if (! $lead->wasChanged($phaseFk)) {
            return;
        }

        $oldPhaseId = $lead->getOriginal($phaseFk);
        $oldPhase   = $oldPhaseId ? LeadPhase::find($oldPhaseId) : null;
        $newPhase   = LeadPhase::find($lead->{$phaseFk});

        if (! $newPhase) {
            return;
        }

        LeadMoved::dispatch($lead, $oldPhase ?? $newPhase, $newPhase);

        // Auto-convert on terminal phases
        if ($newPhase->auto_convert && $newPhase->type->isTerminal() && filled($newPhase->conversion_target)) {
            if (! $lead->conversions()->exists()) {
                try {
                    app(LeadConversionService::class)->convert($lead, $newPhase->conversion_target);
                } catch (Throwable $e) {
                    Log::warning('Lead auto-conversion failed', [
                        'lead_id'   => $lead->getKey(),
                        'converter' => $newPhase->conversion_target,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function handleStatusChanged(Lead $lead): void
    {
        if (! $lead->wasChanged('status')) {
            return;
        }

        $oldRaw = $lead->getOriginal('status');
        $old    = $oldRaw instanceof LeadStatusEnum
            ? $oldRaw
            : LeadStatusEnum::tryFrom((string) ($oldRaw ?? '')) ?? LeadStatusEnum::Active;

        $newRaw = $lead->status;
        $new    = $newRaw instanceof LeadStatusEnum
            ? $newRaw
            : LeadStatusEnum::tryFrom((string) ($newRaw ?? '')) ?? LeadStatusEnum::Active;

        LeadStatusChanged::dispatch($lead, $old, $new);
    }

    private function handleAssignmentChanged(Lead $lead): void
    {
        if (! $lead->wasChanged('assigned_to')) {
            return;
        }

        $newAssignee = $lead->assigned_to;

        if (blank($newAssignee)) {
            return;
        }

        $assignedUser = config('lead-pipeline.user_model')::find($newAssignee);
        $assigner     = auth()->user();

        LeadAssigned::dispatch($lead, $assignedUser, $assigner);
    }
}
