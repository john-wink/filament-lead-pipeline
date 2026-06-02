<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
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
});

function makeMetaSourceWithPage(Team $team, $user, array $sourceOverrides = []): array
{
    $connection = FacebookConnection::query()->create([
        'user_uuid'          => $user->id,
        'team_uuid'          => $team->uuid,
        'facebook_user_id'   => 'fb-' . uniqid(),
        'facebook_user_name' => 'Christoffer R.',
        'access_token'       => 'token-' . uniqid(),
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-' . uniqid(),
        'page_name'                => 'Test Page',
        'page_access_token'        => 'page-token-abc',
        'is_webhooks_subscribed'   => false,
    ]);

    $board = LeadBoard::factory()->create(['team_uuid' => $team->uuid]);

    $source = LeadSource::query()->create(array_merge([
        'name'                             => 'Meta Source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $team->uuid,
        'created_by'                       => $user->getKey(),
        'facebook_page_uuid'               => $page->uuid,
        'facebook_form_ids'                => ['form-1'],
    ], $sourceOverrides));

    return [$source, $page];
}

it('reactivates the webhook on the page when the action is called', function (): void {
    [$source, $page] = makeMetaSourceWithPage($this->team, $this->user);

    Http::fake([
        'graph.facebook.com/*/' . $page->page_id . '/subscribed_apps*' => Http::response(['success' => true]),
    ]);

    livewire(SourceManagement::class)
        ->callTableAction('meta_reactivate_webhook', $source)
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect($page->fresh()->is_webhooks_subscribed)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/' . $page->page_id . '/subscribed_apps')
        && 'POST' === $request->method());
});

it('reports an error notification when the graph api call fails', function (): void {
    [$source, $page] = makeMetaSourceWithPage($this->team, $this->user);

    Http::fake([
        'graph.facebook.com/*/' . $page->page_id . '/subscribed_apps*' => Http::response(['error' => ['message' => 'token expired']], 401),
    ]);

    livewire(SourceManagement::class)
        ->callTableAction('meta_reactivate_webhook', $source)
        ->assertNotified();

    expect($page->fresh()->is_webhooks_subscribed)->toBeFalse();
});

it('does not show the reactivate action for non-meta sources', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $apiSource = LeadSource::factory()
        ->for($board, 'board')
        ->create([
            'team_uuid' => $this->team->uuid,
            'driver'    => 'api',
            'status'    => LeadSourceStatusEnum::Active,
        ]);

    livewire(SourceManagement::class)
        ->assertTableActionHidden('meta_reactivate_webhook', $apiSource);
});

it('hides the reactivate action when the meta source has no page connected', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $metaWithoutPage = LeadSource::query()->create([
        'name'                             => 'Meta No Page',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => null,
    ]);

    livewire(SourceManagement::class)
        ->assertTableActionHidden('meta_reactivate_webhook', $metaWithoutPage);
});
