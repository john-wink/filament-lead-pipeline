<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadActivityFactory;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;

class LeadActivity extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_activities';

    protected $fillable = [
        'type',
        'description',
        'properties',
        'causer_type',
        'causer_id',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, static::fkColumn('lead'));
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): LeadActivityFactory
    {
        return LeadActivityFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type'       => LeadActivityTypeEnum::class,
            'properties' => 'array',
        ];
    }
}
