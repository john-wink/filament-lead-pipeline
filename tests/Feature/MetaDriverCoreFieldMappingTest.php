<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Drivers\MetaDriver;

it('resolves a custom facebook field to a core lead field', function (): void {
    $overrides = MetaDriver::resolveCoreFieldOverrides(
        [['facebook_key' => 'business_email', 'board_field_key' => '__core_email__']],
        ['business_email' => 'biz@example.com', 'full_name' => 'Erika'],
    );

    expect($overrides)->toBe(['email' => 'biz@example.com']);
});

it('resolves name, email and phone core mappings together', function (): void {
    $overrides = MetaDriver::resolveCoreFieldOverrides(
        [
            ['facebook_key' => 'voller_name', 'board_field_key' => '__core_name__'],
            ['facebook_key' => 'geschaeftliche_email', 'board_field_key' => '__core_email__'],
            ['facebook_key' => 'mobil', 'board_field_key' => '__core_phone__'],
        ],
        ['voller_name' => 'Max Muster', 'geschaeftliche_email' => 'm@x.de', 'mobil' => '+49 170 1234567'],
    );

    expect($overrides)->toBe([
        'name'  => 'Max Muster',
        'email' => 'm@x.de',
        'phone' => '+49 170 1234567',
    ]);
});

it('ignores core mappings whose facebook field is missing or empty', function (): void {
    $overrides = MetaDriver::resolveCoreFieldOverrides(
        [
            ['facebook_key' => 'missing_field', 'board_field_key' => '__core_email__'],
            ['facebook_key' => 'empty_field', 'board_field_key' => '__core_phone__'],
        ],
        ['empty_field' => '', 'other' => 'x'],
    );

    expect($overrides)->toBe([]);
});

it('ignores non-core board field keys', function (): void {
    $overrides = MetaDriver::resolveCoreFieldOverrides(
        [
            ['facebook_key' => 'fav_color', 'board_field_key' => 'custom_board_field'],
            ['facebook_key' => 'note', 'board_field_key' => '__ignore__'],
            ['facebook_key' => 'new', 'board_field_key' => '__create__'],
        ],
        ['fav_color' => 'blau', 'note' => 'hi', 'new' => 'x'],
    );

    expect($overrides)->toBe([]);
});
