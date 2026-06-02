<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JohnWink\FilamentLeadPipeline\Database\Factories\FacebookConnectionFactory;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;

class FacebookConnection extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'facebook_connections';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'user_uuid',
        'team_uuid',
        'facebook_user_id',
        'facebook_user_name',
        'access_token',
        'token_expires_at',
        'last_refreshed_at',
        'acquired_at',
        'refresh_attempts',
        'refresh_failed_at',
        'last_error',
        'expiring_soon_notified_at',
        'scopes',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('lead-pipeline.user_model'), 'user_uuid');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(config('lead-pipeline.tenancy.model'), 'team_uuid');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(FacebookPage::class, 'facebook_connection_uuid');
    }

    public function isConnected(): bool
    {
        return FacebookConnectionStatusEnum::Connected === $this->status;
    }

    public function needsReauth(): bool
    {
        return FacebookConnectionStatusEnum::NeedsReauth === $this->status;
    }

    public function isInWarningWindow(CarbonInterface $threshold): bool
    {
        return null !== $this->token_expires_at && $this->token_expires_at->lessThanOrEqualTo($threshold);
    }

    /**
     * Connections the refresher should act on: connected AND either inside the
     * warning window, previously failed transiently, or with unknown expiry.
     */
    public function scopeDueForRefresh(Builder $query, CarbonInterface $threshold): Builder
    {
        return $query
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->where(function (Builder $q) use ($threshold): void {
                $q->where('token_expires_at', '<=', $threshold)
                    ->orWhereNotNull('refresh_failed_at')
                    ->orWhereNull('token_expires_at');
            });
    }

    protected static function newFactory(): FacebookConnectionFactory
    {
        return FacebookConnectionFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token'              => 'encrypted',
            'token_expires_at'          => 'datetime',
            'last_refreshed_at'         => 'datetime',
            'acquired_at'               => 'datetime',
            'refresh_failed_at'         => 'datetime',
            'expiring_soon_notified_at' => 'datetime',
            'refresh_attempts'          => 'integer',
            'scopes'                    => 'array',
            'status'                    => FacebookConnectionStatusEnum::class,
        ];
    }
}
