<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

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
        'facebook_page_uuid',
        'facebook_form_ids',
        'default_assigned_to',
    ];

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
