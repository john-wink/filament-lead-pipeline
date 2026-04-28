<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;

it('starts with no board form extensions', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make();

    expect($plugin->getBoardFormExtensions())->toBe([]);
});

it('registers a board form extension closure', function (): void {
    $plugin    = FilamentLeadPipelinePlugin::make();
    $extension = fn (): array => [];

    $returned = $plugin->extendBoardForm($extension);

    expect($returned)->toBe($plugin)
        ->and($plugin->getBoardFormExtensions())->toBe([$extension]);
});

it('appends multiple extensions in registration order', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make();
    $first  = fn (): array => ['a'];
    $second = fn (): array => ['b'];

    $plugin->extendBoardForm($first);
    $plugin->extendBoardForm($second);

    expect($plugin->getBoardFormExtensions())->toBe([$first, $second]);
});
