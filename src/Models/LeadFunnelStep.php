<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFunnelStepFactory;

class LeadFunnelStep extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_funnel_steps';

    protected $fillable = [
        'name',
        'step_type',
        'description',
        'sort',
        'settings',
    ];

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(LeadFunnel::class, static::fkColumn('lead_funnel'));
    }

    public function fields(): HasMany
    {
        return $this->hasMany(LeadFunnelStepField::class, static::fkColumn('lead_funnel_step'));
    }

    public function showName(): bool
    {
        return $this->settings['show_name'] ?? true;
    }

    public function showDescription(): bool
    {
        return $this->settings['show_description'] ?? true;
    }

    public function isIntro(): bool
    {
        return 'intro' === $this->step_type;
    }

    protected static function newFactory(): LeadFunnelStepFactory
    {
        return LeadFunnelStepFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort'     => 'integer',
            'settings' => 'array',
        ];
    }
}
