<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;

class MetaReachRange extends Model
{
    use HasConfigurablePrimaryKey;

    protected $table = 'meta_reach_ranges';

    protected $guarded = [];

    /**
     * Deterministischer Schlüssel für den Kampagnen-Filter: '' = alle Kampagnen,
     * sonst md5 der sortierten, kommaseparierten campaign_ids.
     *
     * @param  list<string>|null  $campaignIds
     */
    public static function campaignKey(?array $campaignIds): string
    {
        if (null === $campaignIds || [] === $campaignIds) {
            return '';
        }

        sort($campaignIds);

        return md5(implode(',', $campaignIds));
    }

    /** Normalisiert auf Y-m-d, damit Cache-Lookups und Unique-Key dasselbe Format treffen (Arbeitsregel 10). */
    public function setDateFromAttribute(mixed $value): void
    {
        $this->attributes['date_from'] = \Carbon\CarbonImmutable::parse($value)->toDateString();
    }

    public function setDateTillAttribute(mixed $value): void
    {
        $this->attributes['date_till'] = \Carbon\CarbonImmutable::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return [
            'date_from'  => 'date',
            'date_till'  => 'date',
            'fetched_at' => 'datetime',
        ];
    }
}
