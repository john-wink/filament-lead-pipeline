<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('schedules the hourly meta-reports sync with a value-less --skip-creatives flag', function (): void {
    config([
        'lead-pipeline.reports.sync.enabled'            => true,
        'lead-pipeline.reports.sync.hourly_current_day' => true,
    ]);

    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => str_contains((string) $event->command, 'sync-meta-reports')
            && str_contains((string) $event->command, 'skip-creatives')
    );

    expect($event)->not->toBeNull();

    // --skip-creatives is a value-less boolean flag; passing it with a value
    // ("--skip-creatives='1'") makes Symfony reject the scheduled command at runtime.
    expect($event->command)
        ->toContain('--skip-creatives')
        ->not->toContain("--skip-creatives='1'")
        ->not->toContain('--skip-creatives=');
});
