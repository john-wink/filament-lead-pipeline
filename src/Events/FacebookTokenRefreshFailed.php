<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookTokenRefreshFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public FacebookConnection $connection,
        public int $attempt,
    ) {}
}
