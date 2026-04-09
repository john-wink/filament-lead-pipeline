<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\Lead;

class LeadAssigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public ?Model $assignedUser,
        public ?Model $assignedBy,
    ) {}
}
