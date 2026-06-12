<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

function healthConnection(array $attributes = []): FacebookConnection
{
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    return FacebookConnection::factory()->create([
        'team_uuid'        => $team->uuid,
        'user_uuid'        => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
        'status'           => FacebookConnectionStatusEnum::Connected,
        'token_expires_at' => now()->addDays(60),
        'scopes'           => ['ads_read', 'leads_retrieval'],
        ...$attributes,
    ]);
}

it('reports ok for a healthy connection', function (): void {
    $connection = healthConnection();

    expect($connection->healthState())->toBe('ok')
        ->and($connection->healthReasons())->toBe([]);
});

it('reports critical when reauth is needed', function (): void {
    $connection = healthConnection(['status' => FacebookConnectionStatusEnum::NeedsReauth]);

    expect($connection->healthState())->toBe('critical')
        ->and($connection->healthReasons())->toContain('needs_reauth');
});

it('reports warning inside the token expiry window', function (): void {
    $connection = healthConnection(['token_expires_at' => now()->addDays(3)]);

    expect($connection->healthState())->toBe('warning')
        ->and($connection->healthReasons())->toContain('token_expiring');
});

it('reports warning when ads_read scope is missing', function (): void {
    $connection = healthConnection(['scopes' => ['leads_retrieval']]);

    expect($connection->healthState())->toBe('warning')
        ->and($connection->healthReasons())->toContain('missing_ads_read');
});

it('treats critical as stronger than warning', function (): void {
    $connection = healthConnection([
        'status'           => FacebookConnectionStatusEnum::NeedsReauth,
        'token_expires_at' => now()->addDays(3),
    ]);

    expect($connection->healthState())->toBe('critical')
        ->and($connection->healthReasons())->toContain('needs_reauth', 'token_expiring');
});
