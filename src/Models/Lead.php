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
use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFactory;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
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
        'external_id',
        'name',
        'email',
        'phone',
        'status',
        'sort',
        'value',
        'converted_at',
        'lost_at',
        'lost_reason',
        'reminder_at',
        'reminder_note',
        'reminder_notified_at',
        'first_response_at',
        'first_response_by',
        'assigned_to',
        'raw_data',
        'source_campaign_id',
        'source_campaign_name',
        'source_adgroup_id',
        'source_adgroup_name',
        'source_ad_id',
        'source_ad_name',
        'source_channel',
    ];

    /**
     * Atomically determine the next sort value for a phase, avoiding the
     * read-then-write race that collides under concurrent webhook deliveries.
     */
    public static function nextSortForPhase(int|string $phaseKey): int
    {
        return DB::transaction(function () use ($phaseKey): int {
            $max = static::query()
                ->where(static::fkColumn('lead_phase'), $phaseKey)
                ->lockForUpdate()
                ->max('sort');

            return (int) ($max ?? 0) + 1;
        });
    }

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

    public function hasDueReminder(): bool
    {
        return null !== $this->reminder_at && $this->reminder_at->lessThanOrEqualTo(now());
    }

    /** Erwartet das via withMax('activities as last_activity_at', ...) vorgeladene Attribut — sonst Fallback auf created_at. */
    public function daysSinceLastActivity(): int
    {
        $reference = $this->last_activity_at ?? $this->created_at;

        return (int) \Carbon\Carbon::parse($reference)->diffInDays(now());
    }

    /** @return 'fresh'|'aging'|'stale' */
    public function staleness(): string
    {
        $days = $this->daysSinceLastActivity();

        return match (true) {
            $days >= (int) config('lead-pipeline.kanban.stale_critical_days', 30) => 'stale',
            $days >= (int) config('lead-pipeline.kanban.stale_warning_days', 7)   => 'aging',
            default                                                               => 'fresh',
        };
    }

    /**
     * Protokolliert einen ausgehenden Kontaktversuch (Anruf/E-Mail). Sales-Vorgabe:
     * jeder tel:/mailto:-Klick muss als Activity nachvollziehbar sein — wer, wen, wann.
     */
    public function logContactAttempt(string $channel): ?LeadActivity
    {
        $type = match ($channel) {
            'phone' => LeadActivityTypeEnum::Call,
            'email' => LeadActivityTypeEnum::Email,
            default => null,
        };

        if (null === $type) {
            return null;
        }

        $target = 'phone' === $channel ? $this->phone : $this->email;

        return $this->activities()->create([
            'type'        => $type->value,
            'description' => __('lead-pipeline::lead-pipeline.activity.contact_' . $channel, ['target' => (string) $target]),
            'properties'  => ['channel' => $channel, 'target' => $target],
            'causer_type' => config('lead-pipeline.user_model'),
            'causer_id'   => auth()->id(),
        ]);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(LeadConversion::class, static::fkColumn('lead'));
    }

    public function latestConversion(): HasOne
    {
        return $this->hasOne(LeadConversion::class, static::fkColumn('lead'))->latestOfMany();
    }

    public function originLead(): ?self
    {
        if (blank($this->external_id)) {
            return null;
        }

        $source = $this->source;
        if ( ! $source || LeadSourceTypeEnum::InternalTransfer->value !== $source->driver) {
            return null;
        }

        return static::withTrashed()->find($this->external_id);
    }

    public function transferredLeads(): HasMany
    {
        return $this->hasMany(static::class, 'external_id', $this->getKeyName())
            ->whereHas('source', fn (Builder $q): Builder => $q->where('driver', LeadSourceTypeEnum::InternalTransfer->value));
    }

    public function isTransferred(): bool
    {
        return null !== $this->originLead();
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

        $tenant = function_exists('filament') ? filament()->getTenant() : null;
        if ($tenant && $board->isSharedWith($tenant)) {
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

        return $this->refresh();
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status'               => LeadStatusEnum::class,
            'sort'                 => 'integer',
            'value'                => 'decimal:2',
            'converted_at'         => 'datetime',
            'lost_at'              => 'datetime',
            'reminder_at'          => 'datetime',
            'reminder_notified_at' => 'datetime',
            'first_response_at'    => 'datetime',
            'raw_data'             => 'array',
        ];
    }
}
