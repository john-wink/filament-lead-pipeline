<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

/**
 * Lädt die Backfill-Migration als anonyme Migrationsklasse, um up() direkt aufzurufen.
 */
function loadBackfillDisqualifiedMigration(): object
{
    return require dirname(__DIR__, 2) . '/database/migrations/0034_backfill_disqualified_phase_to_boards.php';
}

it('backfills a disqualified phase onto a board that lacks one', function (): void {
    $board = LeadBoard::factory()->create();

    // Der Observer legt beim Erstellen bereits eine disqualified-Phase an —
    // für diesen Test entfernen wir sie, um die Backfill-Lücke zu erzeugen.
    $board->phases()
        ->where('type', LeadPhaseTypeEnum::Disqualified->value)
        ->get()
        ->each(fn ($phase) => $phase->forceDelete());

    expect($board->phases()->where('type', LeadPhaseTypeEnum::Disqualified->value)->count())->toBe(0);

    loadBackfillDisqualifiedMigration()->up();

    $disqualified = $board->phases()->where('type', LeadPhaseTypeEnum::Disqualified->value)->get();

    expect($disqualified)->toHaveCount(1);
    expect($disqualified->first()->display_type)->toBe(LeadPhaseDisplayTypeEnum::List);
});

it('does not duplicate the disqualified phase on a board that already has one', function (): void {
    // Der Observer hat dem Board bereits eine disqualified-Phase verpasst.
    $board = LeadBoard::factory()->create();

    expect($board->phases()->where('type', LeadPhaseTypeEnum::Disqualified->value)->count())->toBe(1);

    loadBackfillDisqualifiedMigration()->up();

    expect($board->phases()->where('type', LeadPhaseTypeEnum::Disqualified->value)->count())->toBe(1);
});
