<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use RuntimeException;

class LeadAlreadyTransferredException extends RuntimeException
{
    public function __construct(
        public readonly Lead $lead,
        public readonly LeadBoard $targetBoard,
    ) {
        parent::__construct(sprintf(
            'Lead %s was already transferred to board %s.',
            $lead->getKey(),
            $targetBoard->getKey(),
        ));
    }
}
