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
        public FacebookConnection $facebookConnection,
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
            'body'               => 'Deine Facebook-Verbindung für "' . $this->facebookConnection->facebook_user_name . '" ist abgelaufen. Bitte verbinde dein Konto neu, damit Leads weiter synchronisiert werden.',
            'connection_uuid'    => $this->facebookConnection->uuid,
            'facebook_user_id'   => $this->facebookConnection->facebook_user_id,
            'facebook_user_name' => $this->facebookConnection->facebook_user_name,
        ];
    }
}
