<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Observers;

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;

class LeadActivityObserver
{
    /**
     * Erste ausgehende Kontaktaktivität (Anruf/E-Mail) stempelt die Erst-
     * Reaktionszeit des Leads — Single Point für Speed-to-Lead/SLA-Metriken.
     */
    public function created(LeadActivity $activity): void
    {
        if ( ! in_array($activity->type, [LeadActivityTypeEnum::Call, LeadActivityTypeEnum::Email], true)) {
            return;
        }

        $lead = $activity->lead;

        if (null === $lead || null !== $lead->first_response_at) {
            return;
        }

        $lead->forceFill([
            'first_response_at' => $activity->created_at,
            'first_response_by' => $activity->causer_id,
        ])->saveQuietly();
    }
}
