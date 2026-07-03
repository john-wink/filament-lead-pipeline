<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Database\Factories\ImmoScoutConnectionFactory;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;

class ImmoScoutConnection extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'immoscout_connections';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'user_uuid',
        'team_uuid',
        'name',
        'consumer_key',
        'consumer_secret',
        'access_token',
        'access_token_secret',
        'scout_id',
        'environment',
        'status',
        'last_error',
        'last_synced_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('lead-pipeline.user_model'), 'user_uuid');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(config('lead-pipeline.tenancy.model'), 'team_uuid');
    }

    public function isConnected(): bool
    {
        return ImmoScoutConnectionStatusEnum::Connected === $this->status;
    }

    public function baseUrl(): string
    {
        return $this->environment->baseUrl();
    }

    protected static function newFactory(): ImmoScoutConnectionFactory
    {
        return ImmoScoutConnectionFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consumer_secret'     => 'encrypted',
            'access_token'        => 'encrypted',
            'access_token_secret' => 'encrypted',
            'environment'         => ImmoScoutEnvironmentEnum::class,
            'status'              => ImmoScoutConnectionStatusEnum::class,
            'last_synced_at'      => 'datetime',
        ];
    }
}
