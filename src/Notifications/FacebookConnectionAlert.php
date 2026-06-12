<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

/*
 * Bewusst ohne Queueable-Trait: dessen $connection-Property (Queue-Verbindung)
 * kollidiert mit der promoteten FacebookConnection-Property (Fatal bei Komposition).
 */
class FacebookConnectionAlert extends Notification
{
    public function __construct(
        public FacebookConnection $connection,
        public string $titleKey,
        /** @var array<string, mixed> */
        public array $titleParams = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** Filament-kompatibles Format, damit der Alert im Panel-Glockensymbol erscheint. */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->danger()
            ->title(__($this->titleKey, [
                'name' => $this->connection->facebook_user_name,
                ...$this->titleParams,
            ]))
            ->body(__('lead-pipeline::lead-pipeline.connection_status.alert_body'))
            ->getDatabaseMessage();
    }
}
