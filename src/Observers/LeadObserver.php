<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Observers;

use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadConversionService;
use Throwable;

class LeadObserver
{
    public function updated(Lead $lead): void
    {
        $phaseFk = Lead::fkColumn('lead_phase');

        if ( ! $lead->wasChanged($phaseFk)) {
            return;
        }

        $newPhase = LeadPhase::find($lead->{$phaseFk});

        if ( ! $newPhase || ! $newPhase->auto_convert || ! $newPhase->type->isTerminal()) {
            return;
        }

        $converterName = $newPhase->conversion_target;

        if (blank($converterName)) {
            return;
        }

        // Prevent double-conversion
        if ($lead->conversions()->exists()) {
            return;
        }

        try {
            $service = app(LeadConversionService::class);
            $service->convert($lead, $converterName);
        } catch (Throwable $e) {
            Log::warning('Lead auto-conversion failed', [
                'lead_id'   => $lead->getKey(),
                'converter' => $converterName,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
