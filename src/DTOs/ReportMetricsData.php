<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Data;

class ReportMetricsData extends Data
{
    /** @param array<string, float|null> $deltas prozentuale Veränderung zum Vorzeitraum je Kennzahl */
    public function __construct(
        public int $impressions,
        public ?int $reach,
        public float $spend,
        public int $clicks,
        public int $linkClicks,
        public int $inquiries,
        public ?float $costPerInquiry,
        public int $qualified,
        public int $won,
        public ?float $costPerWon,
        public array $deltas = [],
    ) {}
}
