<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models;

use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use JohnWink\FilamentLeadPipeline\Concerns\HasLeadBoards;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Factories\TeamFactory;

class Team extends Model implements HasAvatar
{
    use HasFactory;
    use HasLeadBoards;
    use HasUuids;

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user', 'team_uuid', 'user_id');
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return null;
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
