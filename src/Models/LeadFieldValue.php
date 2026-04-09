<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFieldValueFactory;

class LeadFieldValue extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_field_values';

    protected $fillable = [
        'lead_uuid',
        'lead_id',
        'lead_field_definition_uuid',
        'lead_field_definition_id',
        'value',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, static::fkColumn('lead'));
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(LeadFieldDefinition::class, static::fkColumn('lead_field_definition'));
    }

    /**
     * Gibt den getypten Wert basierend auf dem Feldtyp zurueck.
     */
    public function getCastedValueAttribute(): mixed
    {
        if ( ! $this->relationLoaded('definition')) {
            $this->load('definition');
        }

        if ( ! $this->definition) {
            return $this->value;
        }

        return $this->definition->type->castValue($this->value);
    }

    /**
     * Gibt einen menschenlesbaren Wert fuer die Anzeige zurueck.
     * Loest Option-Labels auf und formatiert Waehrungen.
     */
    public function getDisplayValueAttribute(): string
    {
        if ( ! $this->relationLoaded('definition')) {
            $this->load('definition');
        }

        if ( ! $this->definition) {
            return (string) $this->value;
        }

        $type    = $this->definition->type;
        $casted  = $this->casted_value;
        $options = $this->definition->options ?? [];

        // Select/MultiSelect: Resolve labels from definition options or funnel options
        if (\JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Select === $type
            || \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::MultiSelect === $type) {
            $values = is_array($casted) ? $casted : [$casted];

            // Options can be ['key' => 'label'] or [['label' => '...', 'value' => '...']]
            $labels = array_map(function ($val) use ($options): string {
                // Associative: key => label
                if (isset($options[$val])) {
                    return (string) $options[$val];
                }
                // Array of objects: [{label, value}]
                foreach ($options as $opt) {
                    if (is_array($opt) && ($opt['value'] ?? null) === $val) {
                        return $opt['label'] ?? $val;
                    }
                }

                return (string) $val;
            }, $values);

            return implode(', ', $labels);
        }

        // Currency: Format as EUR
        if (\JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Currency === $type) {
            return number_format((float) $casted, 2, ',', '.') . ' €';
        }

        // Boolean
        if (\JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Boolean === $type) {
            return $casted ? __('lead-pipeline::lead-pipeline.field.yes') : __('lead-pipeline::lead-pipeline.field.no');
        }

        // Arrays (fallback)
        if (is_array($casted)) {
            return implode(', ', $casted);
        }

        return (string) $casted;
    }

    protected static function newFactory(): LeadFieldValueFactory
    {
        return LeadFieldValueFactory::new();
    }
}
