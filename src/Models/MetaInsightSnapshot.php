<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\MetaInsightSnapshotFactory;

class MetaInsightSnapshot extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'meta_insight_snapshots';

    protected $guarded = [];

    public function setBreakdownValueAttribute(?string $value): void
    {
        $this->attributes['breakdown_value'] = $value ?? '';
    }

    /** Normalisiert auf Y-m-d, damit create() und upsert() denselben Unique-Key treffen. */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = CarbonImmutable::parse($value)->toDateString();
    }

    protected static function newFactory(): MetaInsightSnapshotFactory
    {
        return MetaInsightSnapshotFactory::new();
    }

    protected function casts(): array
    {
        return [
            'date'  => 'date',
            'spend' => 'decimal:2',
        ];
    }
}
