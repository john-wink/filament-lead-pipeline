<?php

declare(strict_types=1);

test('every nested @livewire component in the kanban page view carries a stable key', function (): void {
    $path  = dirname(__DIR__, 2) . '/resources/views/filament/pages/kanban-board.blade.php';
    $lines = file($path);

    $livewireLines = array_filter($lines, fn (string $line): bool => str_contains($line, "@livewire('lead-pipeline::"));

    expect($livewireLines)->not->toBeEmpty();

    // A keyless @livewire('lead-pipeline::...') is reassigned a fresh auto-id on every
    // page re-render (e.g. switching tabs). For always-present children (connection
    // status / detail & analytics modals) Livewire then emits a snapshot-less placeholder
    // under that new id, and the browser throws "Snapshot missing on Livewire component".
    // Every nested component must therefore be rendered with a stable key().
    foreach ($livewireLines as $line) {
        expect(str_contains($line, 'key('))->toBeTrue('Keyless @livewire: ' . mb_trim($line));
    }
});

test('the kanban page view renders no Livewire component inside an Alpine x-teleport', function (): void {
    $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/filament/pages/kanban-board.blade.php');

    // A Livewire component inside x-teleport is moved out of the page's morph tree;
    // on a parent re-render Livewire cannot reconcile the teleported child and throws
    // "Snapshot missing on Livewire component", aborting hydration of the phase columns.
    // The page view must not use x-teleport.
    expect($blade)->not->toContain('x-teleport');
});
