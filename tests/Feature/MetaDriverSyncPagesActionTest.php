<?php

declare(strict_types=1);

use App\Models\Team;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    LeadBoard::created(function (LeadBoard $board): void {
        $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    });

    $this->connection = FacebookConnection::factory()->create([
        'team_uuid'          => $this->team->uuid,
        'user_uuid'          => $this->user->getKey(),
        'facebook_user_name' => 'Villa Behr',
        'access_token'       => 'user-token-sync-pages',
    ]);

    $this->page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-sync-1',
        'page_name'                => 'Villa Behr Immobilien',
        'page_access_token'        => 'page-token-sync-pages',
    ]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->source = LeadSource::factory()->meta()->active()->for($board, 'board')->create([
        'team_uuid'          => $this->team->uuid,
        'facebook_page_uuid' => $this->page->uuid,
    ]);
});

it('marks the connection as needing a new login instead of failing when facebook rejects the token', function (Closure $rejection): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake(['graph.facebook.com/*/me/accounts*' => $rejection()]);

    livewire(SourceManagement::class)
        ->mountTableAction('edit', $this->source)
        ->assertFormComponentActionEnabled('facebook_page_uuid', 'sync_pages', formName: 'mountedTableActionForm')
        ->callFormComponentAction('facebook_page_uuid', 'sync_pages', formName: 'mountedTableActionForm')
        ->assertNotified(
            Notification::make()
                ->title(__('lead-pipeline::lead-pipeline.facebook.sync_reauth'))
                ->body(__('lead-pipeline::lead-pipeline.facebook.sync_reauth_body'))
                ->danger(),
        )
        ->assertFormComponentActionDisabled('facebook_page_uuid', 'sync_pages', formName: 'mountedTableActionForm')
        ->assertSee(__('lead-pipeline::lead-pipeline.facebook.expired_warning', ['name' => 'Villa Behr']))
        ->assertSee(__('lead-pipeline::lead-pipeline.facebook.reconnect'))
        ->assertDontSee(__('lead-pipeline::lead-pipeline.facebook.connected_as', ['name' => 'Villa Behr']));

    $connection = $this->connection->fresh();

    expect($connection->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth)
        ->and($connection->needsReauth())->toBeTrue()
        ->and($connection->last_error)->toStartWith('Failed to fetch Facebook pages')
        ->and($connection->last_error)->not->toContain('user-token-sync-pages')
        ->and($this->source->fresh()->status)->toBe(LeadSourceStatusEnum::Error)
        ->and(FacebookPage::query()->whereKey($this->page->uuid)->exists())->toBeTrue();

    Event::assertDispatched(
        FacebookConnectionNeedsReauth::class,
        fn (FacebookConnectionNeedsReauth $event): bool => $event->connection->is($this->connection),
    );
})->with([
    'revoked app authorisation (code 190)' => [fn () => Http::response([
        'error' => [
            'message'       => 'Error validating access token: The user has not authorized application 1234567890.',
            'type'          => 'OAuthException',
            'code'          => 190,
            'error_subcode' => 458,
        ],
    ], 400)],
    'unauthorised (HTTP 401)' => [fn () => Http::response([
        'error' => [
            'message' => 'Invalid OAuth access token.',
            'type'    => 'OAuthException',
        ],
    ], 401)],
]);

it('keeps the connection untouched and warns the user when facebook pages cannot be loaded right now', function (Closure $failure): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake(['graph.facebook.com/*/me/accounts*' => $failure()]);

    livewire(SourceManagement::class)
        ->mountTableAction('edit', $this->source)
        ->callFormComponentAction('facebook_page_uuid', 'sync_pages', formName: 'mountedTableActionForm')
        ->assertNotified(
            Notification::make()
                ->title(__('lead-pipeline::lead-pipeline.facebook.sync_failed'))
                ->body(__('lead-pipeline::lead-pipeline.facebook.sync_failed_body'))
                ->warning(),
        )
        ->assertFormComponentActionEnabled('facebook_page_uuid', 'sync_pages', formName: 'mountedTableActionForm')
        ->assertSee(__('lead-pipeline::lead-pipeline.facebook.connected_as', ['name' => 'Villa Behr']));

    $connection = $this->connection->fresh();

    expect($connection->status)->toBe(FacebookConnectionStatusEnum::Connected)
        ->and($connection->last_error)->toBeNull()
        ->and($this->source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and(FacebookPage::query()->whereKey($this->page->uuid)->exists())->toBeTrue();

    Event::assertNotDispatched(FacebookConnectionNeedsReauth::class);
})->with([
    'server error (HTTP 500)' => [fn () => Http::response([
        'error' => ['message' => 'An unexpected error has occurred. Please retry your request later.', 'code' => 2],
    ], 500)],
    'rate limit (code 4)' => [fn () => Http::response([
        'error' => ['message' => 'Application request limit reached', 'code' => 4],
    ], 400)],
    'other graph rejection (code 100)' => [fn () => Http::response([
        'error' => ['message' => 'Unsupported get request.', 'code' => 100],
    ], 400)],
    'network failure' => [fn () => Http::failedConnection()],
]);

it('synchronises the pages and reports the summary when facebook answers successfully', function (): void {
    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            ['id' => 'page-sync-1', 'name' => 'Villa Behr Immobilien', 'access_token' => 'page-token-new', 'tasks' => ['MANAGE', 'ADVERTISE']],
            ['id' => 'page-sync-2', 'name' => 'Villa Behr Neubau', 'access_token' => 'page-token-2', 'tasks' => ['MANAGE', 'ADVERTISE']],
        ]]),
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => [
            ['id' => 'form-1', 'name' => 'Kontaktformular', 'status' => 'ACTIVE'],
        ]]),
    ]);

    livewire(SourceManagement::class)
        ->mountTableAction('edit', $this->source)
        ->callFormComponentAction('facebook_page_uuid', 'sync_pages', formName: 'mountedTableActionForm')
        ->assertNotified(
            Notification::make()
                ->title(__('lead-pipeline::lead-pipeline.facebook.sync_completed'))
                ->body(__('lead-pipeline::lead-pipeline.facebook.sync_summary', [
                    'added'        => 1,
                    'updated'      => 1,
                    'removed'      => 0,
                    'forms_synced' => 2,
                ]))
                ->success(),
        );

    expect(FacebookPage::query()->where('facebook_connection_uuid', $this->connection->uuid)->orderBy('page_id')->pluck('page_id')->all())
        ->toBe(['page-sync-1', 'page-sync-2'])
        ->and($this->page->fresh()->page_access_token)->toBe('page-token-new')
        ->and($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::Connected);
});

it('resolves the sync failure notification texts in every locale', function (string $locale, string $key): void {
    app()->setLocale($locale);

    expect(__("lead-pipeline::lead-pipeline.facebook.{$key}"))
        ->not->toBe("lead-pipeline::lead-pipeline.facebook.{$key}")
        ->not->toBeEmpty();
})->with(['de', 'en', 'fr'])->with(['sync_reauth', 'sync_reauth_body', 'sync_failed', 'sync_failed_body']);
