<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LeadBoardTeamShare extends Model
{
    use HasUuids;

    protected $table = 'lead_board_team_shares';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'owner_team_id',
        'shared_with_type',
        'shared_with_id',
        'shared_with_relation',
        'permissions',
    ];

    public function ownerTeam(): BelongsTo
    {
        /** @var class-string<Model> $tenantModel */
        $tenantModel = config('lead-pipeline.tenancy.model');

        return $this->belongsTo(
            $tenantModel,
            'owner_team_id',
            (new $tenantModel())->getKeyName(),
        );
    }

    public function sharedWith(): MorphTo
    {
        return $this->morphTo('shared_with');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }
}
