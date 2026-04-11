<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use JohnWink\FilamentLeadPipeline\DTOs\FieldPresetData;
use JohnWink\FilamentLeadPipeline\DTOs\PhasePresetData;
use JohnWink\FilamentLeadPipeline\DTOs\SourcePresetData;
use Throwable;

class FilamentLeadPipelinePlugin implements Plugin
{
    protected bool $hasSourceManagement = true;

    protected bool $hasFunnelBuilder = true;

    /** @var array<string, class-string> */
    protected array $converters = [];

    /** @var array<PhasePresetData> */
    protected array $defaultPhases = [];

    /** @var array<FieldPresetData> */
    protected array $defaultFields = [];

    /** @var array<SourcePresetData> */
    protected array $defaultSources = [];

    protected ?Closure $assignableUsersQuery = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public static function getAssignableUsersQuery(): ?Closure
    {
        try {
            return static::get()->assignableUsersQuery;
        } catch (Throwable) {
            return null;
        }
    }

    public static function getAssignableUsers(): \Illuminate\Support\Collection
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = config('lead-pipeline.user_model');
        $query = $model::query();

        $modifier = static::getAssignableUsersQuery();
        if ($modifier) {
            $modifier($query);
        }

        return $query->orderBy('first_name')->get()->each(function ($user): void {
            $user->display_label = $user->name . ' (' . $user->email . ')';
        });
    }

    /**
     * Generates a public-facing URL, using the configured public_url base if set.
     * Use this for all URLs that need to be reachable from outside (funnels, webhooks, OAuth).
     */
    public static function publicUrl(string $path = ''): string
    {
        $base = config('lead-pipeline.public_url');

        if ($base) {
            return mb_rtrim($base, '/') . '/' . mb_ltrim($path, '/');
        }

        return url($path);
    }

    public function getId(): string
    {
        return 'filament-lead-pipeline';
    }

    public function sourceManagement(bool $enabled = true): static
    {
        $this->hasSourceManagement = $enabled;

        return $this;
    }

    public function funnelBuilder(bool $enabled = true): static
    {
        $this->hasFunnelBuilder = $enabled;

        return $this;
    }

    /** @param array<int|string, class-string> $converters */
    public function converters(array $converters): static
    {
        // Ensure string keys: [UserLeadConverter::class] → ['user_lead' => UserLeadConverter::class]
        $keyed = [];
        foreach ($converters as $key => $class) {
            if (is_int($key)) {
                $key = \Illuminate\Support\Str::snake(class_basename($class));
                $key = (string) str($key)->replaceLast('_converter', '')->replaceLast('_lead', '');
            }
            $keyed[$key] = $class;
        }
        $this->converters = $keyed;

        return $this;
    }

    /** @return array<string, class-string> */
    public function getConverters(): array
    {
        return $this->converters;
    }

    public function hasSourceManagementEnabled(): bool
    {
        return $this->hasSourceManagement;
    }

    public function hasFunnelBuilderEnabled(): bool
    {
        return $this->hasFunnelBuilder;
    }

    public function defaultPhases(array $phases): static
    {
        $this->defaultPhases = $phases;

        return $this;
    }

    public function defaultFields(array $fields): static
    {
        $this->defaultFields = $fields;

        return $this;
    }

    public function defaultSources(array $sources): static
    {
        $this->defaultSources = $sources;

        return $this;
    }

    /** @return array<PhasePresetData> */
    public function getDefaultPhases(): array
    {
        return $this->defaultPhases;
    }

    /** @return array<FieldPresetData> */
    public function getDefaultFields(): array
    {
        return $this->defaultFields;
    }

    /** @return array<SourcePresetData> */
    public function getDefaultSources(): array
    {
        return $this->defaultSources;
    }

    public function assignableUsersQuery(?Closure $callback): static
    {
        $this->assignableUsersQuery = $callback;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                Filament\Resources\LeadBoardResource::class,
            ])
            ->pages([
                Filament\Pages\KanbanBoard::class,
                Filament\Pages\SourceManagement::class,
            ])
            ->widgets([
                Filament\Widgets\LeadStatsWidget::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        $service = app(Services\LeadConversionService::class);

        foreach ($this->converters as $name => $class) {
            if (! $service->hasConverter($name)) {
                $service->registerConverter($name, app($class));
            }
        }
    }
}
