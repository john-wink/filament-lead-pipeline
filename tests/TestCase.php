<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelineServiceProvider;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\AdminPanelProvider;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\User;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // Register class aliases so tests can use App\Models\Team and App\Models\User
        // regardless of whether they run standalone or in the host app
        if (! class_exists(\App\Models\Team::class)) {
            class_alias(Fixtures\Models\Team::class, \App\Models\Team::class);
        }
        if (! class_exists(\App\Models\User::class)) {
            class_alias(Fixtures\Models\User::class, \App\Models\User::class);
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            FormsServiceProvider::class,
            TablesServiceProvider::class,
            ActionsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentLeadPipelineServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.guards.web.provider', 'users');

        $app['config']->set('lead-pipeline.primary_key_type', 'uuid');
        $app['config']->set('lead-pipeline.tenancy.enabled', true);
        $app['config']->set('lead-pipeline.tenancy.model', Team::class);
        $app['config']->set('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $app['config']->set('lead-pipeline.user_model', User::class);
        $app['config']->set('lead-pipeline.user_foreign_key', 'user_uuid');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }
}
