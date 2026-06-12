<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Listeners;

use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Notifications\FacebookConnectionAlert;

/**
 * Macht die bisher stillen Token-Events sichtbar: Der Connection-Besitzer
 * bekommt eine Panel-Notification, BEVOR Leads still ausbleiben.
 *
 * Bewusst KEIN handle()-Type-Hint auf ein einzelnes Event: Die Host-App
 * discovered Listener über handle()-Signaturen — explizite Registrierung
 * im ServiceProvider verhindert Doppel-Dispatch.
 */
class SendFacebookConnectionAlerts
{
    public function handle(object $event): void
    {
        if ( ! config('lead-pipeline.facebook.alerts.enabled', true)) {
            return;
        }

        $titleKey = match ($event::class) {
            FacebookTokenExpiringSoon::class     => 'lead-pipeline::lead-pipeline.connection_status.alert_expiring',
            FacebookTokenRefreshFailed::class    => 'lead-pipeline::lead-pipeline.connection_status.alert_refresh_failed',
            FacebookConnectionNeedsReauth::class => 'lead-pipeline::lead-pipeline.connection_status.alert_needs_reauth',
            default                              => null,
        };

        if (null === $titleKey) {
            return;
        }

        $params = FacebookTokenExpiringSoon::class === $event::class ? ['days' => $event->daysLeft] : [];

        $event->connection->user?->notify(
            new FacebookConnectionAlert($event->connection, $titleKey, $params),
        );
    }
}
