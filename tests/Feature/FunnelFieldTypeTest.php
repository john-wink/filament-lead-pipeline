<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;

it('has 10 funnel field types', function (): void {
    expect(FunnelFieldTypeEnum::cases())->toHaveCount(10);
});

it('returns allowed funnel types for board field types', function (): void {
    $allowed = FunnelFieldTypeEnum::allowedFor(LeadFieldTypeEnum::Currency);

    expect($allowed)->toContain(FunnelFieldTypeEnum::TextInput);
    expect($allowed)->toContain(FunnelFieldTypeEnum::OptionCards);
    expect($allowed)->toContain(FunnelFieldTypeEnum::Slider);
    expect($allowed)->not->toContain(FunnelFieldTypeEnum::YesNo);
});

it('returns only email_input for email fields', function (): void {
    $allowed = FunnelFieldTypeEnum::allowedFor(LeadFieldTypeEnum::Email);

    expect($allowed)->toEqual([FunnelFieldTypeEnum::EmailInput]);
});

it('returns view name for rendering', function (): void {
    expect(FunnelFieldTypeEnum::OptionCards->renderView())
        ->toBe('lead-pipeline::funnel.fields.option-cards');
});
