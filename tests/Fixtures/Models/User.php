<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Factories\UserFactory;

class User extends Authenticatable
{
    use HasFactory;
    use HasUuids;
    use Notifiable;

    protected $primaryKey = 'id';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
    ];

    protected $hidden = ['password'];

    public function getNameAttribute(): string
    {
        return mb_trim("{$this->first_name} {$this->last_name}");
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_uuid');
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
