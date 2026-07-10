<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\View\View;
use JohnWink\FilamentLeadPipeline\Concerns\ScopesOperationsLeads;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;
use Livewire\Attributes\On;
use Livewire\Component;

class AdvisorScorecardPanel extends Component
{
    use ScopesOperationsLeads;

    public bool $isOpen = false;

    public ?string $advisorId = null;

    public ?string $boardId = null;

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public string $preset = '30';

    public int $shown = 50;

    #[On('open-advisor-scorecard')]
    public function open(string $advisorId): void
    {
        $this->advisorId = $advisorId;
        $this->shown     = 50;
        $this->isOpen    = true;
    }

    public function close(): void
    {
        $this->isOpen    = false;
        $this->advisorId = null;
    }

    public function loadMore(): void
    {
        $this->shown += 50;
    }

    public function render(): View
    {
        $card     = null;
        $protocol = ['days' => [], 'total' => 0, 'has_more' => false];

        if ($this->isOpen && null !== $this->advisorId) {
            [$from, $to] = $this->operationsRange($this->dateFrom, $this->dateTo, $this->preset);
            $service     = app(LeadActivityMetricsService::class);
            // Berater-Filter hier NICHT anwenden — die Scorecard braucht das Team als Vergleich.
            $leads = fn () => $this->scopedOperationsLeads($this->boardId, null);

            $card     = $service->advisorScorecard($this->advisorId, $leads(), $from, $to);
            $protocol = $service->advisorProtocol($this->advisorId, $leads(), $from, $to, limit: $this->shown);
        }

        return view('lead-pipeline::livewire.advisor-scorecard-panel', [
            'card'     => $card,
            'protocol' => $protocol,
        ]);
    }
}
