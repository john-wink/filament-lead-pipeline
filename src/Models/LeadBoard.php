<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadBoardFactory;
use JohnWink\FilamentLeadPipeline\Enums\RoutingModeEnum;

class LeadBoard extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'lead_boards';

    protected $fillable = [
        'name',
        'description',
        'settings',
        'is_active',
        'sort',
        'team_uuid',
        'routing_mode',
        'recipient_type',
        'recipient_id',
        'routing_settings',
    ];

    public function phases(): HasMany
    {
        return $this->hasMany(LeadPhase::class, static::fkColumn('lead_board'));
    }

    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(LeadFieldDefinition::class, static::fkColumn('lead_board'));
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, static::fkColumn('lead_board'));
    }

    public function sources(): HasMany
    {
        return $this->hasMany(LeadSource::class, static::fkColumn('lead_board'));
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(LeadFunnel::class, static::fkColumn('lead_board'));
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo('recipient');
    }

    public function sharedTenants(): HasMany
    {
        return $this->hasMany(LeadBoardSharedTenant::class, 'lead_board_uuid', 'uuid');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(LeadBoardStat::class, 'lead_board_uuid', 'uuid');
    }

    public function routingModeIs(RoutingModeEnum $mode): bool
    {
        return $this->routing_mode === $mode;
    }

    public function isSharedWith(Model $tenant, ?string $permission = null): bool
    {
        $query = $this->sharedTenants()
            ->where('shared_with_type', $tenant::class)
            ->where('shared_with_id', $tenant->getKey());

        if (null !== $permission) {
            $query->whereJsonContains('permissions', $permission);
        }

        return $query->exists();
    }

    public function hasLeads(): bool
    {
        return $this->leads()->exists();
    }

    public function admins(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        $userModel = config('lead-pipeline.user_model');
        $userFk    = config('lead-pipeline.user_foreign_key', 'user_uuid');
        $boardFk   = static::fkColumn('lead_board');

        return $this->belongsToMany($userModel, 'lead_board_admins', $boardFk, $userFk);
    }

    public function isAdmin(Model $user): bool
    {
        $userFk = config('lead-pipeline.user_foreign_key', 'user_uuid');

        return $this->admins()->where("lead_board_admins.{$userFk}", $user->getKey())->exists();
    }

    public function isAdvisor(Model $user): bool
    {
        return ! $this->isAdmin($user);
    }

    /**
     * Stellt sicher, dass die System-Felder (Name, E-Mail, Telefon) existieren.
     */
    public function ensureSystemFields(): void
    {
        $boardFk      = static::fkColumn('lead_board');
        $existingKeys = LeadFieldDefinition::query()
            ->where($boardFk, $this->getKey())
            ->whereIn('key', ['name', 'email', 'phone'])
            ->pluck('key')
            ->toArray();

        $systemFields = [
            ['name' => __('lead-pipeline::lead-pipeline.field.name'), 'key' => 'name', 'type' => 'string', 'is_required' => true, 'is_system' => true, 'show_in_card' => false, 'show_in_funnel' => true, 'sort' => -3],
            ['name' => __('lead-pipeline::lead-pipeline.field.email'), 'key' => 'email', 'type' => 'email', 'is_required' => false, 'is_system' => true, 'show_in_card' => false, 'show_in_funnel' => true, 'sort' => -2],
            ['name' => __('lead-pipeline::lead-pipeline.field.phone'), 'key' => 'phone', 'type' => 'phone', 'is_required' => false, 'is_system' => true, 'show_in_card' => false, 'show_in_funnel' => true, 'sort' => -1],
        ];

        foreach ($systemFields as $field) {
            if ( ! in_array($field['key'], $existingKeys, true)) {
                $this->fieldDefinitions()->create($field);
            }
        }
    }

    protected static function booted(): void
    {
        static::created(function (LeadBoard $board): void {
            $board->ensureSystemFields();
        });
    }

    protected static function newFactory(): LeadBoardFactory
    {
        return LeadBoardFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings'         => 'array',
            'is_active'        => 'boolean',
            'sort'             => 'integer',
            'routing_mode'     => RoutingModeEnum::class,
            'routing_settings' => 'array',
        ];
    }
}
