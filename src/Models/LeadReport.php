<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadReportFactory;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Enums\ReportSectionEnum;

class LeadReport extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'lead_reports';

    protected $guarded = [];

    public static function generateToken(): string
    {
        return Str::random(40);
    }

    public function boards(): BelongsToMany
    {
        return $this->belongsToMany(LeadBoard::class, 'lead_report_boards', 'report_uuid', 'board_uuid');
    }

    public function adSources(): HasMany
    {
        return $this->hasMany(LeadReportAdSource::class, 'report_uuid');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LeadReportSchedule::class, 'report_uuid');
    }

    public function viewAggregates(): HasMany
    {
        return $this->hasMany(LeadReportView::class, 'report_uuid');
    }

    public function isAccessible(): bool
    {
        if ( ! $this->is_active) {
            return false;
        }

        return null === $this->expires_at || $this->expires_at->isFuture();
    }

    public function requiresPassword(): bool
    {
        return null !== $this->password;
    }

    public function passwordMatches(?string $plain): bool
    {
        return null !== $plain && null !== $this->password && Hash::check($plain, $this->password);
    }

    public function recordView(): void
    {
        $aggregate = $this->viewAggregates()->firstOrCreate(['date' => now()->toDateString()]);
        $aggregate->increment('views');

        $this->increment('views_count');
        $this->forceFill(['last_viewed_at' => now()])->saveQuietly();
    }

    public function rotateToken(): void
    {
        $this->update(['share_token' => self::generateToken()]);
    }

    public function datePresetDefault(): ReportDatePresetEnum
    {
        return ReportDatePresetEnum::tryFrom((string) $this->date_preset_default) ?? ReportDatePresetEnum::Last30Days;
    }

    /** @return list<string> */
    public function enabledSections(): array
    {
        return $this->sections ?? ReportSectionEnum::defaults();
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->share_token ??= self::generateToken();
        });
    }

    protected static function newFactory(): LeadReportFactory
    {
        return LeadReportFactory::new();
    }

    protected function casts(): array
    {
        return [
            'expires_at'        => 'datetime',
            'is_active'         => 'boolean',
            'date_locked'       => 'boolean',
            'funnel_mapping'    => 'array',
            'sections'          => 'array',
            'branding_settings' => 'array',
            'last_viewed_at'    => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
