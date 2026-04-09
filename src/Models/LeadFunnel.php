<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFunnelFactory;

class LeadFunnel extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'lead_funnels';

    protected $fillable = [
        'name',
        'slug',
        'design',
        'success_config',
        'rejection_config',
        'is_active',
        'views_count',
        'submissions_count',
        'lead_board_uuid',
        'lead_board_id',
        'lead_source_uuid',
        'lead_source_id',
        'lead_phase_uuid',
        'lead_phase_id',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, static::fkColumn('lead_source'));
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(LeadBoard::class, static::fkColumn('lead_board'));
    }

    public function targetPhase(): BelongsTo
    {
        return $this->belongsTo(LeadPhase::class, static::fkColumn('lead_phase'));
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LeadFunnelStep::class, static::fkColumn('lead_funnel'));
    }

    /**
     * Returns the design. Missing values are replaced by hardcoded defaults.
     * Filament panel values (Logo, Favicon, Primary Color) are resolved when saving in the builder
     * and stored in the design — only static fallbacks apply here.
     *
     * @return array<string, mixed>
     */
    public function getResolvedDesign(): array
    {
        $defaults = [
            'background_color'  => '#F9FAFB',
            'primary_color'     => '#3B82F6',
            'text_color'        => '#1F2937',
            'font_family'       => 'Inter, system-ui, sans-serif',
            'border_radius'     => '12px',
            'max_width'         => '540px',
            'logo_position'     => 'center',
            'show_progress_bar' => true,
            'show_step_numbers' => false,
            'logo_url'          => null,
            'favicon_url'       => null,
            'background_image'  => null,
            'custom_css'        => '',
        ];

        $saved    = $this->design ?? [];
        $filtered = array_filter($saved, fn ($v) => filled($v));

        return array_merge($defaults, $filtered);
    }

    /**
     * Gibt die oeffentliche URL des Funnels zurueck.
     */
    public function getPublicUrl(): string
    {
        $prefix = config('lead-pipeline.funnel.route_prefix', 'funnel');

        return \JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::publicUrl("{$prefix}/{$this->slug}");
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementSubmissions(): void
    {
        $this->increment('submissions_count');
    }

    /**
     * Berechnet die Konversionsrate (Submissions / Views).
     */
    public function conversionRate(): float
    {
        if (0 === $this->views_count) {
            return 0.0;
        }

        return round(($this->submissions_count / $this->views_count) * 100, 2);
    }

    protected static function newFactory(): LeadFunnelFactory
    {
        return LeadFunnelFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'design'            => 'array',
            'success_config'    => 'array',
            'rejection_config'  => 'array',
            'is_active'         => 'boolean',
            'views_count'       => 'integer',
            'submissions_count' => 'integer',
        ];
    }
}
