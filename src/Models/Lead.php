<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFactory;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;

class Lead extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'leads';

    /** @var array<string> */
    protected $hidden = ['raw_data'];

    protected $fillable = [
        'lead_board_uuid',
        'lead_board_id',
        'lead_phase_uuid',
        'lead_phase_id',
        'lead_source_uuid',
        'lead_source_id',
        'name',
        'email',
        'phone',
        'status',
        'sort',
        'value',
        'converted_at',
        'lost_at',
        'lost_reason',
        'assigned_to',
        'raw_data',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, static::fkColumn('lead_board'));
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(LeadPhase::class, static::fkColumn('lead_phase'));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, static::fkColumn('lead_source'));
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(
            config('lead-pipeline.user_model'),
            'assigned_to',
        );
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(LeadFieldValue::class, static::fkColumn('lead'));
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class, static::fkColumn('lead'));
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(LeadConversion::class, static::fkColumn('lead'));
    }

    public function latestConversion(): HasOne
    {
        return $this->hasOne(LeadConversion::class, static::fkColumn('lead'))->latestOfMany();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LeadStatusEnum::Active);
    }

    /**
     * Filters leads based on role visibility.
     * Admins see everything. Advisors only see their assigned leads.
     */
    public function scopeVisibleTo(Builder $query, $user, LeadBoard $board): Builder
    {
        if ($board->isAdmin($user)) {
            return $query;
        }

        return $query->where('assigned_to', $user->getKey());
    }

    /**
     * Setzt den Wert eines benutzerdefinierten Feldes.
     */
    public function setFieldValue(LeadFieldDefinition $definition, mixed $value): LeadFieldValue
    {
        $fkLead       = static::fkColumn('lead');
        $fkDefinition = static::fkColumn('lead_field_definition');

        return $this->fieldValues()->updateOrCreate(
            [
                $fkLead       => $this->getKey(),
                $fkDefinition => $definition->getKey(),
            ],
            ['value' => is_array($value) ? json_encode($value) : (string) $value],
        );
    }

    /**
     * Gibt den Wert eines benutzerdefinierten Feldes zurueck.
     */
    public function getFieldValue(string $key): mixed
    {
        $fieldValue = $this->fieldValues()
            ->whereHas('definition', fn (Builder $q) => $q->where('key', $key))
            ->first();

        if ( ! $fieldValue) {
            return null;
        }

        return $fieldValue->casted_value;
    }

    /**
     * Verschiebt den Lead in eine andere Phase und protokolliert die Aenderung.
     */
    public function moveToPhase(LeadPhase $phase): self
    {
        $oldPhase = $this->phase;

        $this->update([
            static::fkColumn('lead_phase') => $phase->getKey(),
        ]);

        $this->activities()->create([
            'type'        => LeadActivityTypeEnum::Moved->value,
            'description' => sprintf(
                __('lead-pipeline::lead-pipeline.activity.moved_from_to'),
                $oldPhase?->name ?? __('lead-pipeline::lead-pipeline.activity.no_phase'),
                $phase->name,
            ),
            'properties' => [
                'old_phase' => $oldPhase?->getKey(),
                'new_phase' => $phase->getKey(),
            ],
        ]);

        $this->refresh();

        \JohnWink\FilamentLeadPipeline\Events\LeadMoved::dispatch($this, $oldPhase ?? $phase, $phase);

        return $this;
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status'       => LeadStatusEnum::class,
            'sort'         => 'integer',
            'value'        => 'decimal:2',
            'converted_at' => 'datetime',
            'lost_at'      => 'datetime',
            'raw_data'     => 'array',
        ];
    }
}
