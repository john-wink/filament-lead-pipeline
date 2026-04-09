<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadConversionFactory;

class LeadConversion extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_conversions';

    protected $fillable = [
        'convertible_type',
        'convertible_id',
        'converter_class',
        'metadata',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, static::fkColumn('lead'));
    }

    public function convertible(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): LeadConversionFactory
    {
        return LeadConversionFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
