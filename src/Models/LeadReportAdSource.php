<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadReportAdSourceFactory;

class LeadReportAdSource extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_report_ad_sources';

    protected $guarded = [];

    public function report(): BelongsTo
    {
        return $this->belongsTo(LeadReport::class, 'report_uuid');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FacebookConnection::class, 'facebook_connection_uuid');
    }

    protected static function newFactory(): LeadReportAdSourceFactory
    {
        return LeadReportAdSourceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'campaign_ids'   => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
