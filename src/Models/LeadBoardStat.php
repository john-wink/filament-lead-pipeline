<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;

class LeadBoardStat extends Model
{
    use HasConfigurablePrimaryKey;

    protected $table = 'lead_board_stats';

    protected $fillable = [
        'lead_board_uuid',
        'period_date',
        'counts',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, 'lead_board_uuid', 'uuid');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'counts'      => 'array',
            'period_date' => 'date',
        ];
    }
}
