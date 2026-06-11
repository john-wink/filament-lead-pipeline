<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use JohnWink\FilamentLeadPipeline\Contracts\ResolvesReportBranding;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Services\ReportMetricsService;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;
use Livewire\Attributes\Url;
use Livewire\Component;

class PublicReportPage extends Component
{
    public string $token = '';

    #[Url(as: 'zeitraum')]
    public string $preset = '';

    #[Url(as: 'von')]
    public ?string $customFrom = null;

    #[Url(as: 'bis')]
    public ?string $customTill = null;

    public bool $unlocked = false;

    public string $passwordInput = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $report      = $this->report();

        // Signierte URLs (absolute Signatur) umgehen das Passwort-Gate — für den headless PDF-Renderer
        $this->unlocked = ! $report->requiresPassword()
            || session()->get($this->sessionKey(), false)
            || request()->hasValidSignature();

        if ('' === $this->preset || $report->date_locked) {
            $this->preset = $report->datePresetDefault()->value;
        }

        if ($this->unlocked) {
            $report->recordView();
        }
    }

    public function unlock(): void
    {
        $report = $this->report();

        if ($report->passwordMatches($this->passwordInput)) {
            session()->put($this->sessionKey(), true);
            $this->unlocked = true;
            $report->recordView();

            return;
        }

        $this->addError('passwordInput', __('lead-pipeline::reports.password_invalid'));
    }

    public function setPreset(string $preset): void
    {
        $report = $this->report();

        if ($report->date_locked) {
            $this->preset = $report->datePresetDefault()->value;

            return;
        }

        $this->preset = (ReportDatePresetEnum::tryFrom($preset) ?? $report->datePresetDefault())->value;
    }

    public function render(): View
    {
        $report  = $this->report();
        $range   = $this->range($report);
        $metrics = app(ReportMetricsService::class);

        return view('lead-pipeline::reports.page', [
            'report'    => $report,
            'range'     => $range,
            'branding'  => app(ResolvesReportBranding::class)->resolve($report),
            'data'      => $this->unlocked ? $metrics->metrics($report, $range) : null,
            'trend'     => $this->unlocked ? $metrics->trend($report, $range) : [],
            'gender'    => $this->unlocked ? $metrics->genderBreakdown($report, $range) : null,
            'funnel'    => $this->unlocked ? $metrics->funnel($report, $range) : [],
            'creatives' => $this->unlocked ? $metrics->creatives($report) : collect(),
            'syncedAt'  => $metrics->lastSyncedAt($report),
        ])->layout('lead-pipeline::reports.layout', [
            // Gesperrter Zustand: generischer Titel, sonst stünde der Report-Name trotz Passwort-Gate im HTML
            'title' => $this->unlocked ? $report->name : __('lead-pipeline::reports.password_required'),
        ]);
    }

    private function report(): LeadReport
    {
        $report = LeadReport::query()->where('share_token', $this->token)->first();

        abort_if(null === $report || ! $report->isAccessible(), 404);

        return $report;
    }

    private function range(LeadReport $report): ReportDateRange
    {
        $preset = ReportDatePresetEnum::tryFrom($this->preset) ?? $report->datePresetDefault();

        return ReportDateRange::fromPreset(
            $preset,
            null !== $this->customFrom ? CarbonImmutable::make($this->customFrom) : null,
            null !== $this->customTill ? CarbonImmutable::make($this->customTill) : null,
        );
    }

    private function sessionKey(): string
    {
        return "lead-report-unlocked:{$this->token}";
    }
}
