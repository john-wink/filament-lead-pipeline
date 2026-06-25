<?php

declare(strict_types=1);

test('the kanban tab-content branches carry distinct wire:keys so morphdom remounts them cleanly on tab switch', function (): void {
    $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/filament/pages/kanban-board.blade.php');

    // Without distinct wire:keys on the @if/@else wrapper divs, switching from a
    // list tab to the board tab morphs the list container into the board container
    // in place; the freshly-rendered nested @livewire phase columns never register
    // their snapshot -> "Snapshot missing on Livewire component".
    expect($blade)
        ->toContain('wire:key="kanban-tab-content-board"')
        ->toContain('wire:key="kanban-tab-content-list-');
});
