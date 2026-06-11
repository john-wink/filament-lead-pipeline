<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Support\LeadActionSum;

it('prefers the lead action type when present (no double counting)', function (): void {
    $actions = [
        ['action_type' => 'lead', 'value' => '18'],
        ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '15'],
        ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '3'],
        ['action_type' => 'link_click', 'value' => '120'],
    ];

    expect(LeadActionSum::fromActions($actions))->toBe(18);
});

it('sums onsite and offsite leads when lead total is absent', function (): void {
    $actions = [
        ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '15'],
        ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '3'],
    ];

    expect(LeadActionSum::fromActions($actions))->toBe(18);
});

it('returns zero for null or empty actions', function (): void {
    expect(LeadActionSum::fromActions(null))->toBe(0)
        ->and(LeadActionSum::fromActions([]))->toBe(0);
});
