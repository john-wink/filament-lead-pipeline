<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Concerns;

use JohnWink\FilamentLeadPipeline\Tests\Fixtures\ZeroIntegrationsPanelProvider;

/**
 * Add via Pest's `uses()` in a specific test file to boot the
 * zero-integrations panel fixture alongside the default admin panel,
 * without affecting the panel setup of any other test file.
 */
trait RegistersZeroIntegrationsPanel
{
    /** @return array<int, class-string> */
    protected function additionalPackageProviders(): array
    {
        return [
            ZeroIntegrationsPanelProvider::class,
        ];
    }
}
