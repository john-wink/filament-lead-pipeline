<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\MetaAdCreativeFactory;

class MetaAdCreative extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'meta_ad_creatives';

    protected $guarded = [];

    protected static function newFactory(): MetaAdCreativeFactory
    {
        return MetaAdCreativeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
