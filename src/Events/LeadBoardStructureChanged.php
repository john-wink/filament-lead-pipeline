<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

class LeadBoardStructureChanged
{
    use Dispatchable;
    use SerializesModels;

    /** @param array<string, mixed> $details */
    public function __construct(
        public LeadBoard $board,
        public string $change,
        public array $details,
        public ?Model $causer,
    ) {}
}
