<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
});

function makeConnection(Team $team, $user, array $overrides = []): FacebookConnection
{
    return FacebookConnection::query()->create(array_merge([
        'user_uuid'          => $user->id,
        'team_uuid'          => $team->uuid,
        'facebook_user_id'   => 'fb-user-' . uniqid(),
        'facebook_user_name' => 'Christoffer Riefenstahl',
        'access_token'       => 'token-' . uniqid(),
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ], $overrides));
}

function makePage(FacebookConnection $connection, string $pageId, string $pageName, array $overrides = []): FacebookPage
{
    return FacebookPage::query()->create(array_merge([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => $pageId,
        'page_name'                => $pageName,
        'page_access_token'        => 'page-token-' . $pageId,
        'is_webhooks_subscribed'   => false,
    ], $overrides));
}

it('reports a page as OK when graph api confirms leadgen subscription', function (): void {
    $connection = makeConnection($this->team, $this->user);
    $page       = makePage($connection, 'page-ok', 'XCapital Immobilien');

    Http::fake([
        'graph.facebook.com/*/page-ok/subscribed_apps*' => Http::response([
            'data' => [['id' => 'our-app', 'subscribed_fields' => ['leadgen']]],
        ]),
    ]);

    $this->artisan('lead-pipeline:facebook-webhook-status')
        ->expectsOutputToContain('XCapital Immobilien')
        ->expectsOutputToContain('OK')
        ->assertSuccessful();

    expect($page->fresh()->is_webhooks_subscribed)->toBeTrue();
});

it('reports a page as NICHT ABONNIERT when graph api shows no leadgen', function (): void {
    $connection = makeConnection($this->team, $this->user);
    $page       = makePage($connection, 'page-broken', 'Eigentumswohnung Bonn', [
        'is_webhooks_subscribed' => true,
    ]);

    Http::fake([
        'graph.facebook.com/*/page-broken/subscribed_apps*' => Http::response(['data' => []]),
    ]);

    $this->artisan('lead-pipeline:facebook-webhook-status')
        ->expectsOutputToContain('Eigentumswohnung Bonn')
        ->expectsOutputToContain('NICHT ABONNIERT')
        ->assertSuccessful();

    expect($page->fresh()->is_webhooks_subscribed)->toBeFalse();
});

it('skips graph api calls for expired connections', function (): void {
    $connection = makeConnection($this->team, $this->user, [
        'status'           => 'needs_reauth',
        'token_expires_at' => now()->subDay(),
    ]);
    makePage($connection, 'page-skip', 'Expired Page');

    Http::fake();

    $this->artisan('lead-pipeline:facebook-webhook-status')
        ->expectsOutputToContain('Expired Page')
        ->expectsOutputToContain('TOKEN ABGELAUFEN')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('reports FEHLER when graph api throws for a page', function (): void {
    $connection = makeConnection($this->team, $this->user);
    makePage($connection, 'page-err', 'Broken Token Page');

    Http::fake([
        'graph.facebook.com/*/page-err/subscribed_apps*' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    $this->artisan('lead-pipeline:facebook-webhook-status')
        ->expectsOutputToContain('Broken Token Page')
        ->expectsOutputToContain('FEHLER')
        ->assertSuccessful();
});

it('shows forms mapped to active lead sources for a page', function (): void {
    $connection = makeConnection($this->team, $this->user);
    $page       = makePage($connection, 'page-forms', 'Forms Page');

    foreach (['form-a', 'form-b', 'form-c'] as $formId) {
        FacebookForm::query()->create([
            'facebook_page_uuid' => $page->uuid,
            'form_id'            => $formId,
            'form_name'          => 'Form ' . $formId,
            'status'             => 'active',
            'cached_at'          => now(),
        ]);
    }

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    LeadSource::query()->create([
        'name'                             => 'Source A',
        'driver'                           => 'meta',
        'status'                           => 'active',
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'facebook_page_uuid'               => $page->uuid,
        'facebook_form_ids'                => ['form-a', 'form-b'],
    ]);

    Http::fake([
        'graph.facebook.com/*/page-forms/subscribed_apps*' => Http::response([
            'data' => [['id' => 'our-app', 'subscribed_fields' => ['leadgen']]],
        ]),
    ]);

    $this->artisan('lead-pipeline:facebook-webhook-status')
        ->expectsOutputToContain('Forms Page')
        ->expectsOutputToContain('2/3')
        ->assertSuccessful();
});

it('filters by team slug', function (): void {
    $otherTeam = Team::factory()->create(['slug' => 'other-team']);
    $otherTeam->users()->syncWithoutDetaching([$this->user->id]);

    $connectionMine  = makeConnection($this->team, $this->user);
    $connectionOther = makeConnection($otherTeam, $this->user, [
        'facebook_user_id' => 'fb-user-other',
    ]);

    makePage($connectionMine, 'page-mine', 'My Page');
    makePage($connectionOther, 'page-other', 'Other Team Page');

    Http::fake([
        'graph.facebook.com/*/page-mine/subscribed_apps*'  => Http::response(['data' => [['subscribed_fields' => ['leadgen']]]]),
        'graph.facebook.com/*/page-other/subscribed_apps*' => Http::response(['data' => [['subscribed_fields' => ['leadgen']]]]),
    ]);

    $this->artisan('lead-pipeline:facebook-webhook-status', ['--team' => 'test'])
        ->expectsOutputToContain('My Page')
        ->doesntExpectOutputToContain('Other Team Page')
        ->assertSuccessful();
});
