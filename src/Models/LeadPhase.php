<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadPhaseFactory;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;

class LeadPhase extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'lead_phases';

    protected $fillable = [
        'name',
        'color',
        'type',
        'display_type',
        'sort',
        'auto_convert',
        'conversion_target',
        'settings',
        'lead_board_uuid',
        'lead_board_id',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, static::fkColumn('lead_board'));
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, static::fkColumn('lead_phase'));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort');
    }

    public function scopeKanban(Builder $query): Builder
    {
        return $query->where('display_type', LeadPhaseDisplayTypeEnum::Kanban);
    }

    public function scopeList(Builder $query): Builder
    {
        return $query->where('display_type', LeadPhaseDisplayTypeEnum::List);
    }

    public function leadsCount(): int
    {
        return $this->leads()->count();
    }

    protected static function newFactory(): LeadPhaseFactory
    {
        return LeadPhaseFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type'         => LeadPhaseTypeEnum::class,
            'display_type' => LeadPhaseDisplayTypeEnum::class,
            'sort'         => 'integer',
            'auto_convert' => 'boolean',
            'settings'     => 'array',
        ];
    }
}
