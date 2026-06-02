<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookRefreshHealthCheckFailed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  \Illuminate\Support\Collection<int, FacebookConnection>  $stuckConnections
     */
    public function __construct(
        public \Illuminate\Support\Collection $stuckConnections,
        public int $thresholdHours,
    ) {}
}
