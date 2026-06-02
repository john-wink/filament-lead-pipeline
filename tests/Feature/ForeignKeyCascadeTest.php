<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
});

it('cascade-deletes facebook pages and forms when the connection is hard-deleted', function (): void {
    $connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);
    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'p-cascade', 'page_name' => 'P', 'page_access_token' => 't',
    ]);
    $form = FacebookForm::query()->create([
        'facebook_page_uuid' => $page->uuid, 'form_id' => 'f-cascade', 'form_name' => 'F',
    ]);

    // FacebookConnection has no SoftDeletes -> hard DELETE -> DB cascade fires.
    $connection->delete();

    expect(FacebookPage::withTrashed()->whereKey($page->uuid)->exists())->toBeFalse()
        ->and(FacebookForm::query()->whereKey($form->uuid)->exists())->toBeFalse();
});
