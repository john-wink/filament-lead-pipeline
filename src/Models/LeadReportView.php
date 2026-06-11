<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;

class LeadReportView extends Model
{
    use HasConfigurablePrimaryKey;

    protected $table = 'lead_report_views';

    protected $guarded = [];

    /** Normalisiert auf Y-m-d, damit firstOrCreate(['date' => now()->toDateString()]) den Key trifft. */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = CarbonImmutable::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
