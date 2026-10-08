<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\LeadSourceLifecycle;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    $this->actingAs($this->user);
});

function endedStatusSource(LeadSourceStatusEnum $status, ?LeadBoard $board = null): LeadSource
{
    return LeadSource::factory()
        ->for($board ?? test()->board, 'board')
        ->create(['team_uuid' => test()->team->uuid, 'status' => $status]);
}

it('maps every status to exactly one tab group', function (LeadSourceStatusEnum $status, bool $inActiveGroup): void {
    expect($status->isInActiveGroup())->toBe($inActiveGroup)
        ->and(in_array($status, LeadSourceStatusEnum::activeGroup(), true))->toBe($inActiveGroup)
        ->and(in_array($status, LeadSourceStatusEnum::inactiveGroup(), true))->toBe( ! $inActiveGroup);
})->with([
    'draft'  => [LeadSourceStatusEnum::Draft, true],
    'active' => [LeadSourceStatusEnum::Active, true],
    'paused' => [LeadSourceStatusEnum::Paused, false],
    'error'  => [LeadSourceStatusEnum::Error, false],
    'ended'  => [LeadSourceStatusEnum::Ended, false],
]);

it('covers all statuses with the two tab groups', function (): void {
    expect([...LeadSourceStatusEnum::activeGroup(), ...LeadSourceStatusEnum::inactiveGroup()])
        ->toHaveCount(count(LeadSourceStatusEnum::cases()));
});

it('describes the ended status with a gray color and an icon of its own', function (): void {
    expect(LeadSourceStatusEnum::Ended->value)->toBe('ended')
        ->and(LeadSourceStatusEnum::Ended->getColor())->toBe('gray')
        ->and(LeadSourceStatusEnum::Ended->getIcon())->not->toBe(LeadSourceStatusEnum::Draft->getIcon());

    app()->setLocale('de');
    expect(LeadSourceStatusEnum::Ended->getLabel())->toBe('Beendet');

    app()->setLocale('en');
    expect(LeadSourceStatusEnum::Ended->getLabel())->toBe('Ended');

    app()->setLocale('fr');
    expect(LeadSourceStatusEnum::Ended->getLabel())->toBe('Terminée');
});

it('scopes sources by tab group', function (): void {
    $draft  = endedStatusSource(LeadSourceStatusEnum::Draft);
    $active = endedStatusSource(LeadSourceStatusEnum::Active);
    $paused = endedStatusSource(LeadSourceStatusEnum::Paused);
    $error  = endedStatusSource(LeadSourceStatusEnum::Error);
    $ended  = endedStatusSource(LeadSourceStatusEnum::Ended);

    expect(LeadSource::query()->inActiveGroup()->pluck(LeadSource::pkColumn())->all())
        ->toEqualCanonicalizing([$draft->getKey(), $active->getKey()])
        ->and(LeadSource::query()->inInactiveGroup()->pluck(LeadSource::pkColumn())->all())
        ->toEqualCanonicalizing([$paused->getKey(), $error->getKey(), $ended->getKey()]);
});

it('ends and reactivates a source', function (): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Error);
    $source->update(['error_message' => 'kaputt']);

    expect($source->isEnded())->toBeFalse()
        ->and($source->end())->toBeTrue()
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($source->isEnded())->toBeTrue()
        ->and($source->reactivate())->toBeTrue()
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($source->fresh()->error_message)->toBeNull();
});

it('knows whether it is the last source of the active group on its board', function (): void {
    $only = endedStatusSource(LeadSourceStatusEnum::Active);
    endedStatusSource(LeadSourceStatusEnum::Paused);
    endedStatusSource(LeadSourceStatusEnum::Ended);

    expect($only->isLastInActiveGroupOnBoard())->toBeTrue();

    endedStatusSource(LeadSourceStatusEnum::Draft);

    expect($only->isLastInActiveGroupOnBoard())->toBeFalse();
});

it('applies an automatic status to a source that has not been ended', function (LeadSourceStatusEnum $status): void {
    $source = endedStatusSource($status);

    expect($source->applyAutomaticStatus(LeadSourceStatusEnum::Error, 'kaputt'))->toBeTrue()
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Error)
        ->and($source->fresh()->error_message)->toBe('kaputt');
})->with([
    'draft'  => [LeadSourceStatusEnum::Draft],
    'active' => [LeadSourceStatusEnum::Active],
    'paused' => [LeadSourceStatusEnum::Paused],
]);

it('never applies an automatic status to an ended source', function (LeadSourceStatusEnum $status): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Ended);

    expect($source->applyAutomaticStatus($status, 'kaputt'))->toBeFalse()
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($source->fresh()->error_message)->toBeNull();
})->with([
    'error'  => [LeadSourceStatusEnum::Error],
    'active' => [LeadSourceStatusEnum::Active],
]);

it('never applies an automatic status through a stale instance of an ended source', function (): void {
    $stale = endedStatusSource(LeadSourceStatusEnum::Active);

    LeadSource::query()->whereKey($stale->getKey())->update(['status' => LeadSourceStatusEnum::Ended]);

    expect($stale->applyAutomaticStatus(LeadSourceStatusEnum::Error, 'kaputt'))->toBeFalse()
        ->and($stale->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($stale->hasBeenEnded())->toBeTrue();
});

it('ends a source and leaves the board untouched by default', function (): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Active);

    app(LeadSourceLifecycle::class)->end($source);

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});

it('ends a source and deactivates its board on request', function (): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Active);

    app(LeadSourceLifecycle::class)->end($source, deactivateBoard: true);

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($this->board->fresh()->is_active)->toBeFalse();
});

it('does not deactivate a board the user does not administer', function (): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Active);
    $this->board->admins()->detach($this->user->getKey());

    $lifecycle = app(LeadSourceLifecycle::class);

    expect($lifecycle->canDeactivateBoardOf($source))->toBeFalse();

    $lifecycle->end($source, deactivateBoard: true);

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});

it('offers the board deactivation only for an active board the user administers', function (): void {
    $source    = endedStatusSource(LeadSourceStatusEnum::Active);
    $lifecycle = app(LeadSourceLifecycle::class);

    expect($lifecycle->canDeactivateBoardOf($source))->toBeTrue()
        ->and($lifecycle->canActivateBoardOf($source))->toBeFalse();

    $this->board->update(['is_active' => false]);

    expect($lifecycle->canDeactivateBoardOf($source->fresh()))->toBeFalse()
        ->and($lifecycle->canActivateBoardOf($source->fresh()))->toBeTrue();

    $this->actingAs(User::factory()->create());

    expect($lifecycle->canActivateBoardOf($source->fresh()))->toBeFalse();
});

it('reactivates a source and activates its board on request', function (): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Ended);
    $this->board->update(['is_active' => false]);

    $lifecycle = app(LeadSourceLifecycle::class);
    $lifecycle->reactivate($source);

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($this->board->fresh()->is_active)->toBeFalse();

    $source->end();
    $lifecycle->reactivate($source, activateBoard: true);

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});

it('ends many sources and deactivates only boards without a remaining source of the active group', function (): void {
    $emptied = $this->board;
    $kept    = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $foreign = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $kept->admins()->syncWithoutDetaching([$this->user->getKey()]);

    $first   = endedStatusSource(LeadSourceStatusEnum::Active, $emptied);
    $second  = endedStatusSource(LeadSourceStatusEnum::Draft, $emptied);
    $third   = endedStatusSource(LeadSourceStatusEnum::Active, $kept);
    $fourth  = endedStatusSource(LeadSourceStatusEnum::Active, $foreign);
    $already = endedStatusSource(LeadSourceStatusEnum::Ended, $emptied);
    endedStatusSource(LeadSourceStatusEnum::Draft, $kept);
    endedStatusSource(LeadSourceStatusEnum::Paused, $emptied);

    $ended = app(LeadSourceLifecycle::class)->endMany(collect([$first, $second, $third, $fourth, $already]));

    expect($ended)->toBe(4)
        ->and($emptied->fresh()->is_active)->toBeFalse()
        ->and($kept->fresh()->is_active)->toBeTrue()
        ->and($foreign->fresh()->is_active)->toBeTrue()
        ->and($fourth->fresh()->status)->toBe(LeadSourceStatusEnum::Ended);
});

it('ends many sources without touching boards when asked not to', function (): void {
    $source = endedStatusSource(LeadSourceStatusEnum::Active);

    $ended = app(LeadSourceLifecycle::class)->endMany(collect([$source]), deactivateEmptiedBoards: false);

    expect($ended)->toBe(1)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});
