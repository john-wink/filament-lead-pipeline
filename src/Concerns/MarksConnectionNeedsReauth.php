<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Concerns;

use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

trait MarksConnectionNeedsReauth
{
    protected function markConnectionNeedsReauth(FacebookConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($reason, 1000),
        ])->save();

        $connection->pages()
            ->whereHas('leadSources')
            ->each(function (FacebookPage $page): void {
                $page->leadSources()->get()->each(function (LeadSource $source): void {
                    $source->applyAutomaticStatus(
                        LeadSourceStatusEnum::Error,
                        'Facebook-Verbindung erfordert einen erneuten Login.',
                    );
                });
            });

        FacebookConnectionNeedsReauth::dispatch($connection, $reason);
    }
}
