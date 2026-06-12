<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Notifications\FacebookConnectionAlert;

function alertConnection(): FacebookConnection
{
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    return FacebookConnection::factory()->create([
        'team_uuid' => $team->uuid,
        'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
        'status'    => FacebookConnectionStatusEnum::Connected,
    ]);
}

it('notifies the connection owner when the token expires soon', function (): void {
    Notification::fake();
    $connection = alertConnection();

    event(new FacebookTokenExpiringSoon($connection, 3));

    Notification::assertSentTo($connection->user, FacebookConnectionAlert::class);
});

it('notifies the connection owner when the refresh fails', function (): void {
    Notification::fake();
    $connection = alertConnection();

    event(new FacebookTokenRefreshFailed($connection, 2));

    Notification::assertSentTo($connection->user, FacebookConnectionAlert::class);
});

it('notifies the connection owner when reauth is needed', function (): void {
    Notification::fake();
    $connection = alertConnection();

    event(new FacebookConnectionNeedsReauth($connection, 'token invalid'));

    Notification::assertSentTo($connection->user, FacebookConnectionAlert::class);
});

it('sends nothing when alerts are disabled', function (): void {
    config()->set('lead-pipeline.facebook.alerts.enabled', false);
    Notification::fake();
    $connection = alertConnection();

    event(new FacebookConnectionNeedsReauth($connection, 'token invalid'));

    Notification::assertNothingSent();
});
