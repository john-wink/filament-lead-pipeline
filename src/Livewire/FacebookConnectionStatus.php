<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use Livewire\Component;

class FacebookConnectionStatus extends Component
{
    public function refreshToken(string $connectionUuid): void
    {
        $connection = FacebookConnection::query()
            ->where(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'), filament()->getTenant()?->getKey())
            ->find($connectionUuid);

        if (null === $connection) {
            return;
        }

        RefreshFacebookConnection::dispatch($connection);

        Notification::make()->success()
            ->title(__('lead-pipeline::lead-pipeline.connection_status.refresh_queued'))
            ->send();
    }

    public function render(): View
    {
        $connections = FacebookConnection::query()
            ->where(config('lead-pipeline.tenancy.foreign_key', 'team_uuid'), filament()->getTenant()?->getKey())
            ->withCount('pages')
            ->orderBy('facebook_user_name')
            ->get();

        return view('lead-pipeline::components.facebook-connection-status', [
            'connections' => $connections,
        ]);
    }
}
