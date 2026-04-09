<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use InvalidArgumentException;
use JohnWink\FilamentLeadPipeline\Contracts\LeadSourceDriver;

class LeadSourceManager
{
    /** @var array<string, string> */
    protected array $drivers = [];

    /** @var array<string, LeadSourceDriver> */
    protected array $resolvedDrivers = [];

    public function __construct()
    {
        $this->drivers = config('lead-pipeline.drivers', []);
    }

    /** @return array<string, string> */
    public function getAvailableDrivers(): array
    {
        return $this->drivers;
    }

    public function getDriver(string $name): LeadSourceDriver
    {
        if (isset($this->resolvedDrivers[$name])) {
            return $this->resolvedDrivers[$name];
        }

        if ( ! isset($this->drivers[$name])) {
            throw new InvalidArgumentException(
                sprintf('Lead source driver [%s] is not registered.', $name),
            );
        }

        $driverClass = $this->drivers[$name];

        if ( ! class_exists($driverClass)) {
            throw new InvalidArgumentException(
                sprintf('Lead source driver class [%s] does not exist.', $driverClass),
            );
        }

        $driver = app($driverClass);

        if ( ! $driver instanceof LeadSourceDriver) {
            throw new InvalidArgumentException(
                sprintf('Lead source driver [%s] must implement %s.', $driverClass, LeadSourceDriver::class),
            );
        }

        $this->resolvedDrivers[$name] = $driver;

        return $driver;
    }

    public function registerDriver(string $name, string $driverClass): void
    {
        $this->drivers[$name] = $driverClass;

        unset($this->resolvedDrivers[$name]);
    }
}
