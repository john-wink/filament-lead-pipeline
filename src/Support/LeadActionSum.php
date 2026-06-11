<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Support;

final class LeadActionSum
{
    private const string TOTAL_TYPE = 'lead';

    private const array PARTIAL_TYPES = [
        'onsite_conversion.lead_grouped',
        'offsite_conversion.fb_pixel_lead',
    ];

    /** @param list<array{action_type?: string, value?: int|string}>|null $actions */
    public static function fromActions(?array $actions): int
    {
        if (null === $actions || [] === $actions) {
            return 0;
        }

        $byType = [];

        foreach ($actions as $action) {
            $byType[$action['action_type'] ?? ''] = (int) ($action['value'] ?? 0);
        }

        if (array_key_exists(self::TOTAL_TYPE, $byType)) {
            return $byType[self::TOTAL_TYPE];
        }

        return array_sum(array_intersect_key($byType, array_flip(self::PARTIAL_TYPES)));
    }
}
