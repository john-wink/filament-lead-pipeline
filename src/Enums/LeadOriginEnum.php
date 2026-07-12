<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

enum LeadOriginEnum: string
{
    case Realtime = 'realtime';
    case Import   = 'import';
    case Manual   = 'manual';
}
