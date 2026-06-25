<?php

declare(strict_types=1);

test('the kanban lead card renders phone and email as plain text, not as clickable contact links', function (): void {
    $card = file_get_contents(dirname(__DIR__, 2) . '/resources/views/kanban/lead-card-inline.blade.php');

    // tel:/mailto: links on the card swallow the card click (open-lead-detail) via
    // @click.stop, so the user lands in their mail/phone client instead of the modal.
    expect($card)
        ->not->toContain('href="mailto:')
        ->not->toContain('href="tel:')
        ->not->toContain('logContact');
});

test('the lead detail modal keeps the clickable, logged contact actions', function (): void {
    $modal = file_get_contents(dirname(__DIR__, 2) . '/resources/views/kanban/lead-detail-modal.blade.php');

    expect($modal)
        ->toContain('href="mailto:')
        ->toContain('href="tel:')
        ->toContain('logContact');
});
