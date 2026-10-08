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
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadSourceFactory;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;

class LeadSource extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'lead_sources';

    protected $fillable = [
        'name',
        'driver',
        'status',
        'config',
        'api_token',
        'webhook_secret',
        'last_received_at',
        'error_message',
        'lead_board_uuid',
        'lead_board_id',
        'team_uuid',
        'created_by',
        'facebook_page_uuid',
        'facebook_page_id',
        'facebook_form_ids',
        'default_assigned_to',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('lead-pipeline.user_model'), 'created_by');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, static::fkColumn('lead_board'));
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, static::fkColumn('lead_source'));
    }

    public function funnel(): HasOne
    {
        return $this->hasOne(LeadFunnel::class, static::fkColumn('lead_source'));
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_uuid');
    }

    public function defaultAssignedUser(): BelongsTo
    {
        return $this->belongsTo(config('lead-pipeline.user_model'), 'default_assigned_to');
    }

    public function isDraft(): bool
    {
        return LeadSourceStatusEnum::Draft === $this->status;
    }

    public function isActive(): bool
    {
        return LeadSourceStatusEnum::Active === $this->status;
    }

    public function isEnded(): bool
    {
        return LeadSourceStatusEnum::Ended === $this->status;
    }

    public function hasBeenEnded(): bool
    {
        return static::withTrashed()
            ->whereKey($this->getKey())
            ->where('status', LeadSourceStatusEnum::Ended)
            ->exists();
    }

    public function end(): bool
    {
        return $this->update(['status' => LeadSourceStatusEnum::Ended]);
    }

    public function reactivate(): bool
    {
        return $this->update([
            'status'        => LeadSourceStatusEnum::Active,
            'error_message' => null,
        ]);
    }

    public function applyAutomaticStatus(LeadSourceStatusEnum $status, ?string $errorMessage = null): bool
    {
        if ($this->hasBeenEnded()) {
            return false;
        }

        return $this->update([
            'status'        => $status,
            'error_message' => $errorMessage,
        ]);
    }

    public function isLastInActiveGroupOnBoard(): bool
    {
        return static::query()
            ->where(static::fkColumn('lead_board'), $this->{static::fkColumn('lead_board')})
            ->whereKeyNot($this->getKey())
            ->inActiveGroup()
            ->doesntExist();
    }

    public function scopeInActiveGroup(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), LeadSourceStatusEnum::activeGroup());
    }

    public function scopeInInactiveGroup(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), LeadSourceStatusEnum::inactiveGroup());
    }

    public function scopeVisibleToUser(Builder $query, ?Model $user, ?Model $tenant): Builder
    {
        $query->when(
            $tenant,
            fn (Builder $tenantQuery) => $tenantQuery->whereHas(
                'board',
                fn (Builder $boardQuery) => $boardQuery->visibleToTenant($tenant),
            ),
        );

        if (null === $user) {
            return $query;
        }

        $userFk = 'lead_board_admins.' . config('lead-pipeline.user_foreign_key', 'user_uuid');

        return $query->where(function (Builder $visible) use ($user, $userFk): void {
            $visible->where('created_by', $user->getKey())
                ->orWhere(function (Builder $administered) use ($user, $userFk): void {
                    $administered
                        ->where(function (Builder $shared): void {
                            $shared->whereNotIn('driver', ['meta', 'zapier'])
                                ->orWhereNull('created_by');
                        })
                        ->whereHas('board', fn (Builder $board) => $board->whereHas(
                            'admins',
                            fn (Builder $admins) => $admins->where($userFk, $user->getKey()),
                        ));
                });
        });
    }

    /**
     * Keeps the stable external Facebook page id in sync with the internal
     * page link. It survives a disconnect (which deletes the FacebookPage
     * rows) and lets the page synchronizer relink this source on reconnect.
     */
    protected static function booted(): void
    {
        static::saving(function (self $source): void {
            if ($source->isDirty('facebook_page_uuid') && ! $source->isDirty('facebook_page_id')) {
                $source->facebook_page_id = null === $source->facebook_page_uuid
                    ? null
                    : FacebookPage::withTrashed()->find($source->facebook_page_uuid)?->page_id;
            }
        });
    }

    protected static function newFactory(): LeadSourceFactory
    {
        return LeadSourceFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status'            => LeadSourceStatusEnum::class,
            'config'            => 'array',
            'facebook_form_ids' => 'array',
            'last_received_at'  => 'datetime',
        ];
    }
}
