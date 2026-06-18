<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Filament\Pages\WebhookLogs;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('renders the webhook logs page', function (): void {
    livewire(WebhookLogs::class)->assertSuccessful();
});

it('shows webhook logs for the current tenant', function (): void {
    $log = LeadWebhookLog::create([
        'team_uuid'   => $this->team->uuid,
        'event_type'  => WebhookLogEventType::Incoming,
        'outcome'     => 'created',
        'http_status' => 201,
    ]);

    livewire(WebhookLogs::class)->assertCanSeeTableRecords([$log]);
});
