<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LeadBoardSharedTenant extends Model
{
    public $incrementing = false;

    protected $table = 'lead_board_shared_tenants';

    protected $primaryKey = null;

    protected $fillable = [
        'lead_board_uuid',
        'shared_with_type',
        'shared_with_id',
        'permissions',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, 'lead_board_uuid', 'uuid');
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
