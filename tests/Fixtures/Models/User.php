<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models;

use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Factories\UserFactory;

class User extends Authenticatable implements HasTenants
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

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->teams->contains($tenant);
    }

    /** @return array<Model>|Collection<int, Model> */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->teams;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
