<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JohnWink\FilamentLeadPipeline\Database\Factories\FacebookConnectionFactory;

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
        return 'connected' === $this->status;
    }

    public function isExpired(): bool
    {
        return 'expired' === $this->status || ($this->token_expires_at && $this->token_expires_at->isPast());
    }

    protected static function newFactory(): FacebookConnectionFactory
    {
        return FacebookConnectionFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token'     => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes'           => 'array',
        ];
    }
}
