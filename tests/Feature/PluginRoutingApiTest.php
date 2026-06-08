<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Contracts\RecipientResolverContract;
use JohnWink\FilamentLeadPipeline\Contracts\StatsAggregatorContract;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

it('exposes recipientResolvers fluent setter and getter', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make();

    $resolver = new class() implements RecipientResolverContract {
        public function label(): string
        {
            return 'Team';
        }

        public function optionsQuery(?Model $context = null): Builder
        {
            return new Builder(new Illuminate\Database\Query\Builder(DB::connection()));
        }

        public function resolveModel(string $id): ?Model
        {
            return null;
        }
    };

    $returned = $plugin->recipientResolvers([$resolver::class]);

    expect($returned)->toBe($plugin)
        ->and($plugin->getRecipientResolvers())->toBe([$resolver::class]);
});

it('exposes shareableTenants closure setter and getter', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make();

    $closure = fn (LeadBoard $board): array => ['team-a', 'team-b'];

    $returned = $plugin->shareableTenants($closure);

    expect($returned)->toBe($plugin)
        ->and($plugin->getShareableTenants())->toBe($closure);
});

it('exposes shareableTenantRelations fluent setter and getter', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make();

    $returned = $plugin->shareableTenantRelations([
        'children' => 'Child teams',
        'partners',
    ]);

    expect($returned)->toBe($plugin)
        ->and($plugin->getConfiguredShareableTenantRelations())->toBe([
            'children' => 'Child teams',
            'partners' => 'Partners',
        ]);
});

it('exposes statsAggregator setter accepting a contract instance', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make();

    $aggregator = new class() implements StatsAggregatorContract {
        public function aggregate(LeadBoard $board, Carbon\CarbonInterface $period): array
        {
            return ['total' => 0];
        }
    };

    $returned = $plugin->statsAggregator($aggregator);

    expect($returned)->toBe($plugin)
        ->and($plugin->getStatsAggregator())->toBe($aggregator);
});
