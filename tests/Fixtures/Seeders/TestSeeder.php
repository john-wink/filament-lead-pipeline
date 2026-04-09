<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\User;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::query()->firstOrCreate(
            ['slug' => 'test'],
            ['name' => 'Test Team'],
        );

        $user = User::factory()->create([
            'first_name' => 'Test',
            'last_name'  => 'Admin',
            'email'      => 'admin@test.com',
        ]);

        $team->users()->syncWithoutDetaching([$user->id]);
    }
}
