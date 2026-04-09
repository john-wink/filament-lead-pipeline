<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Faker\Factory;
use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

class GenerateDemoDataCommand extends Command
{
    protected $signature = 'lead-pipeline:demo-data
        {--board= : Board UUID or ID (required)}
        {--count=50 : Number of leads to generate}
        {--with-activities : Generate activities (moved, notes)}
        {--phase-distribution=realistic : Distribution: equal or realistic}';

    protected $description = 'Generate demo leads for a board with realistic custom field values';

    public function handle(): int
    {
        $boardId = $this->option('board');
        if ( ! $boardId) {
            $this->error('--board is required.');

            return self::FAILURE;
        }

        $board = LeadBoard::with(['phases', 'fieldDefinitions', 'sources'])->find($boardId);
        if ( ! $board) {
            $this->error("Board '{$boardId}' not found.");

            return self::FAILURE;
        }

        $count          = (int) $this->option('count');
        $withActivities = $this->option('with-activities');
        $distribution   = $this->option('phase-distribution');

        $faker   = Factory::create('de_DE');
        $phases  = $board->phases()->ordered()->get();
        $fields  = $board->fieldDefinitions;
        $sources = $board->sources;

        if ($phases->isEmpty()) {
            $this->error('Board has no phases.');

            return self::FAILURE;
        }

        $phaseDistribution = $this->calculateDistribution($phases, $count, $distribution);
        $boardFk           = LeadBoard::fkColumn('lead_board');
        $phaseFk           = LeadPhase::fkColumn('lead_phase');
        $sourceFk          = Lead::fkColumn('lead_source');

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($phaseDistribution as $phaseId => $phaseCount) {
            for ($i = 0; $i < $phaseCount; $i++) {
                $leadData = [
                    $boardFk => $board->getKey(),
                    $phaseFk => $phaseId,
                    'name'   => $faker->name(),
                    'email'  => $faker->email(),
                    'phone'  => $faker->phoneNumber(),
                    'status' => LeadStatusEnum::Active,
                    'value'  => $faker->numberBetween(10000, 500000),
                    'sort'   => $i,
                ];

                if ($sources->isNotEmpty()) {
                    $leadData[$sourceFk] = $sources->random()->getKey();
                }

                $lead = Lead::query()->create($leadData);

                // Custom Fields
                foreach ($fields as $field) {
                    $value = $this->generateFieldValue($faker, $field);
                    if (null !== $value) {
                        $lead->setFieldValue($field, $value);
                    }
                }

                if ($withActivities) {
                    $lead->activities()->create([
                        'type'        => LeadActivityTypeEnum::Created->value,
                        'description' => 'Demo lead created',
                    ]);

                    if ($faker->boolean(30)) {
                        $lead->activities()->create([
                            'type'        => LeadActivityTypeEnum::Note->value,
                            'description' => $faker->sentence(),
                        ]);
                    }
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("{$count} leads created, distributed across {$phases->count()} phases.");

        return self::SUCCESS;
    }

    /** @param \Illuminate\Support\Collection<int, LeadPhase> $phases */
    private function calculateDistribution(\Illuminate\Support\Collection $phases, int $total, string $mode): array
    {
        $dist       = [];
        $phaseCount = $phases->count();

        if ('equal' === $mode) {
            $perPhase  = intdiv($total, $phaseCount);
            $remainder = $total % $phaseCount;

            foreach ($phases as $i => $phase) {
                $dist[$phase->getKey()] = $perPhase + ($i < $remainder ? 1 : 0);
            }
        } else {
            // Realistic: 40%, 25%, 15%, 10%, 5%, 5% ...
            $percentages = [0.40, 0.25, 0.15, 0.10, 0.05, 0.05];
            $assigned    = 0;

            foreach ($phases as $i => $phase) {
                $pct                    = $percentages[$i] ?? 0.02;
                $count                  = (int) round($total * $pct);
                $dist[$phase->getKey()] = $count;
                $assigned += $count;
            }

            // Remainder goes to first phase
            if ($assigned !== $total) {
                $firstKey = $phases->first()->getKey();
                $dist[$firstKey] += ($total - $assigned);
            }
        }

        return $dist;
    }

    private function generateFieldValue(\Faker\Generator $faker, mixed $field): mixed
    {
        return match ($field->type->value) {
            'email'        => $faker->email(),
            'phone'        => $faker->phoneNumber(),
            'string'       => $faker->words(3, true),
            'number'       => $faker->numberBetween(1, 1000),
            'decimal'      => $faker->randomFloat(2, 1, 10000),
            'currency'     => $faker->numberBetween(50000, 500000),
            'boolean'      => $faker->boolean(),
            'date'         => $faker->dateTimeBetween('-1 year')->format('Y-m-d'),
            'textarea'     => $faker->paragraph(),
            'url'          => $faker->url(),
            'select'       => ! empty($field->options) ? $faker->randomElement(array_keys($field->options)) : null,
            'multi_select' => ! empty($field->options) ? $faker->randomElements(array_keys($field->options), min(2, count($field->options))) : null,
            default        => null,
        };
    }
}
