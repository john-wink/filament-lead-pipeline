<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Filament\Forms\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFieldDefinitionFactory;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;

class LeadFieldDefinition extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'lead_field_definitions';

    protected $fillable = [
        'lead_board_uuid',
        'lead_board_id',
        'name',
        'key',
        'type',
        'options',
        'rules',
        'is_required',
        'is_system',
        'show_in_card',
        'show_in_funnel',
        'sort',
        'settings',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, static::fkColumn('lead_board'));
    }

    public function values(): HasMany
    {
        return $this->hasMany(LeadFieldValue::class, static::fkColumn('lead_field_definition'));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort');
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    public function scopeVisibleOnCard(Builder $query): Builder
    {
        return $query->where('show_in_card', true);
    }

    public function scopeVisibleInFunnel(Builder $query): Builder
    {
        return $query->where('show_in_funnel', true);
    }

    /**
     * Creates a Filament form component based on the field type.
     */
    public function toFormComponent(): Component
    {
        $component = $this->type->toFormComponent($this->key, $this->options);

        $component->label($this->name)
            ->required($this->is_required);

        return $component;
    }

    protected static function newFactory(): LeadFieldDefinitionFactory
    {
        return LeadFieldDefinitionFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type'           => LeadFieldTypeEnum::class,
            'options'        => 'array',
            'rules'          => 'array',
            'is_required'    => 'boolean',
            'is_system'      => 'boolean',
            'show_in_card'   => 'boolean',
            'show_in_funnel' => 'boolean',
            'sort'           => 'integer',
            'settings'       => 'array',
        ];
    }
}
