<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookConnectionExpired extends Notification
{
    use Queueable;

    public function __construct(
        public FacebookConnection $connection,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title'              => 'Facebook-Verbindung abgelaufen',
            'body'               => 'Deine Facebook-Verbindung für "' . $this->connection->facebook_user_name . '" ist abgelaufen. Bitte verbinde dein Konto neu, damit Leads weiter synchronisiert werden.',
            'connection_uuid'    => $this->connection->uuid,
            'facebook_user_id'   => $this->connection->facebook_user_id,
            'facebook_user_name' => $this->connection->facebook_user_name,
        ];
    }
}
