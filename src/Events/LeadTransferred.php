<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

class LeadTransferred
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $originLead,
        public Lead $newLead,
        public ?LeadBoard $fromBoard,
        public LeadBoard $toBoard,
        public ?Model $causer,
    ) {}
}
