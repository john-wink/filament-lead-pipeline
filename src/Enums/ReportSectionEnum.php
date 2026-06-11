<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

enum ReportSectionEnum: string
{
    case Kpis      = 'kpis';
    case Trend     = 'trend';
    case Gender    = 'gender';
    case Funnel    = 'funnel';
    case Creatives = 'creatives';
    case Claim     = 'claim';

    /** @return list<string> */
    public static function defaults(): array
    {
        return array_map(fn (self $section): string => $section->value, self::cases());
    }
}
