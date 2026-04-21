<?php

declare(strict_types=1);

use App\Models\Team;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);

    config()->set('lead-pipeline.facebook.client_id', 'test-client-id');
    config()->set('lead-pipeline.facebook.scopes', ['pages_show_list']);
});

it('redirects the user to the Facebook OAuth dialog with state and scopes', function (): void {
    $response = $this->get(route('lead-pipeline.facebook.redirect') . '?team=' . $this->team->uuid);

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('https://www.facebook.com/')
        ->toContain('dialog/oauth')
        ->toContain('client_id=test-client-id')
        ->toContain('scope=pages_show_list')
        ->toContain('state=');
});

it('persists a nonce in the session for callback verification', function (): void {
    $response = $this->get(route('lead-pipeline.facebook.redirect'));

    $response->assertRedirect();
    expect($response->getSession()->get('facebook_oauth_nonce'))->toBeString()->not->toBeEmpty();
});

it('encodes the team-id from the query string into the OAuth state payload', function (): void {
    $response = $this->get(route('lead-pipeline.facebook.redirect') . '?team=' . $this->team->uuid);

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    parse_str(parse_url($location, PHP_URL_QUERY), $params);
    $state = json_decode(base64_decode($params['state']), true);

    expect($state)->toHaveKey('nonce')
        ->and($state['team'])->toBe($this->team->uuid);
});
