<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadBoardFactory;
use JohnWink\FilamentLeadPipeline\Enums\RoutingModeEnum;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use Throwable;

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

    public function reports(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(LeadReport::class, 'lead_report_boards', 'board_uuid', 'report_uuid');
    }

    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(LeadFieldDefinition::class, static::fkColumn('lead_board'));
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, static::fkColumn('lead_board'));
    }

    /** Duplikat-Check bei manueller Anlage: gleiche E-Mail oder Telefonnummer auf diesem Board. */
    public function findDuplicateLead(?string $email, ?string $phone): ?Lead
    {
        if (blank($email) && blank($phone)) {
            return null;
        }

        return $this->leads()
            ->where(function (Builder $query) use ($email, $phone): void {
                if (filled($email)) {
                    $query->orWhere('email', $email);
                }
                if (filled($phone)) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();
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

    public function teamShares(): HasMany
    {
        return $this->hasMany(
            LeadBoardTeamShare::class,
            'owner_team_id',
            config('lead-pipeline.tenancy.foreign_key', 'team_uuid'),
        );
    }

    public function explicitTeamShares(): HasMany
    {
        return $this->teamShares()->whereNull('shared_with_relation');
    }

    public function relationTeamShares(): HasMany
    {
        return $this->teamShares()->whereNotNull('shared_with_relation');
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
            ->whereIn('shared_with_type', static::tenantShareTypes($tenant))
            ->where('shared_with_id', $tenant->getKey());

        if (null !== $permission) {
            $query->whereJsonContains('permissions', $permission);
        }

        if ($query->exists()) {
            return true;
        }

        return $this->sharedViaTeamShareWith($tenant, $permission);
    }

    public function isAccessibleByTenant(?Model $tenant): bool
    {
        if (null === $tenant || ! config('lead-pipeline.tenancy.enabled')) {
            return true;
        }

        $tenantFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');

        return $this->{$tenantFk} === $tenant->getKey()
            || $this->isSharedWith($tenant);
    }

    public function scopeVisibleToTenant(Builder $query, ?Model $tenant): Builder
    {
        if (null === $tenant || ! config('lead-pipeline.tenancy.enabled')) {
            return $query;
        }

        $tenantFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $tenantId = $tenant->getKey();

        return $query->where(function (Builder $q) use ($tenant, $tenantFk, $tenantId): void {
            $q->where($tenantFk, $tenantId)
                ->orWhere(fn (Builder $sharedQuery) => static::applySharedWithTenantConstraint($sharedQuery, $tenant));
        });
    }

    public function scopeSharedWithTenant(Builder $query, ?Model $tenant): Builder
    {
        if (null === $tenant || ! config('lead-pipeline.tenancy.enabled')) {
            return $query->whereRaw('1 = 0');
        }

        return static::applySharedWithTenantConstraint($query, $tenant);
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

    protected static function applySharedWithTenantConstraint(Builder $query, Model $tenant): Builder
    {
        $tenantId = $tenant->getKey();

        return $query->where(function (Builder $q) use ($tenant, $tenantId): void {
            $tenantTypes = static::tenantShareTypes($tenant);

            $q->whereHas('sharedTenants', fn (Builder $shareQuery) => $shareQuery
                ->whereIn('shared_with_type', $tenantTypes)
                ->where('shared_with_id', $tenantId))
                ->orWhereHas('teamShares', fn (Builder $shareQuery) => $shareQuery
                    ->whereNull('shared_with_relation')
                    ->whereIn('shared_with_type', $tenantTypes)
                    ->where('shared_with_id', $tenantId));

            foreach (FilamentLeadPipelinePlugin::getShareableTenantRelations() as $relation => $label) {
                try {
                    $q->orWhere(function (Builder $relationQuery) use ($relation, $tenant): void {
                        $relationQuery
                            ->whereHas('teamShares', fn (Builder $shareQuery) => $shareQuery
                                ->where('shared_with_relation', $relation))
                            ->whereHas('team', fn (Builder $teamQuery) => $teamQuery
                                ->whereHas($relation, fn (Builder $relatedQuery) => $relatedQuery->whereKey($tenant->getKey())));
                    });
                } catch (Throwable) {
                    continue;
                }
            }
        });
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

    /**
     * @return array<int, string>
     */
    protected static function tenantShareTypes(Model $tenant): array
    {
        return array_values(array_unique(array_filter([
            $tenant->getMorphClass(),
            $tenant::class,
            config('lead-pipeline.tenancy.model'),
        ])));
    }

    protected function sharedViaTeamShareWith(Model $tenant, ?string $permission = null): bool
    {
        $query = $this->teamShares()
            ->whereNull('shared_with_relation')
            ->whereIn('shared_with_type', static::tenantShareTypes($tenant))
            ->where('shared_with_id', $tenant->getKey());

        if (null !== $permission) {
            $query->whereJsonContains('permissions', $permission);
        }

        if ($query->exists()) {
            return true;
        }

        foreach (FilamentLeadPipelinePlugin::getShareableTenantRelations() as $relation => $label) {
            try {
                $hasRelationShare = $this->teamShares()
                    ->where('shared_with_relation', $relation)
                    ->when(null !== $permission, fn (Builder $shareQuery) => $shareQuery->whereJsonContains('permissions', $permission))
                    ->exists();

                if ( ! $hasRelationShare || ! $this->team) {
                    continue;
                }

                if ($this->team->{$relation}()->whereKey($tenant->getKey())->exists()) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
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
