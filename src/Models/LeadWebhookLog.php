<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;

class LeadWebhookLog extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'lead_webhook_logs';

    protected $fillable = [
        'team_uuid',
        'lead_source_uuid',
        'facebook_page_uuid',
        'page_id',
        'lead_uuid',
        'event_type',
        'driver',
        'outcome',
        'http_status',
        'message',
        'request',
        'response',
    ];

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_uuid');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_uuid');
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_uuid');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => WebhookLogEventType::class,
            'request'    => 'array',
            'response'   => 'array',
            'created_at' => 'datetime',
        ];
    }
}
