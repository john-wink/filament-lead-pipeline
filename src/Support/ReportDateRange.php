<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Support;

use Carbon\CarbonImmutable;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;

final readonly class ReportDateRange
{
    public function __construct(
        public ReportDatePresetEnum $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $till,
    ) {}

    public static function fromPreset(
        ReportDatePresetEnum $preset,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $till = null,
    ): self {
        $today = CarbonImmutable::now()->startOfDay();

        return match ($preset) {
            ReportDatePresetEnum::Today      => new self($preset, $today, $today),
            ReportDatePresetEnum::Last7Days  => new self($preset, $today->subDays(7), $today->subDay()),
            ReportDatePresetEnum::Last30Days => new self($preset, $today->subDays(30), $today->subDay()),
            ReportDatePresetEnum::Last90Days => new self($preset, $today->subDays(90), $today->subDay()),
            ReportDatePresetEnum::ThisMonth  => new self($preset, $today->startOfMonth(), $today),
            ReportDatePresetEnum::LastMonth  => new self($preset, $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            ReportDatePresetEnum::AllTime    => new self($preset, CarbonImmutable::parse('2020-01-01'), $today),
            ReportDatePresetEnum::Custom     => ($from instanceof CarbonImmutable && $till instanceof CarbonImmutable && $till->gte($from))
                ? new self($preset, $from->startOfDay(), $till->startOfDay())
                : self::fromPreset(ReportDatePresetEnum::Last30Days),
        };
    }

    public function previous(): self
    {
        $days = $this->days();

        return new self(
            ReportDatePresetEnum::Custom,
            $this->from->subDays($days),
            $this->from->subDay(),
        );
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->till) + 1;
    }
}
