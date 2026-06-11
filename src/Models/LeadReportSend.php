<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;

class LeadReportSend extends Model
{
    use HasConfigurablePrimaryKey;

    protected $table = 'lead_report_sends';

    protected $guarded = [];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LeadReportSchedule::class, 'schedule_uuid');
    }

    protected function casts(): array
    {
        return [
            'sent_at'      => 'datetime',
            'recipients'   => 'array',
            'pdf_attached' => 'boolean',
        ];
    }
}
