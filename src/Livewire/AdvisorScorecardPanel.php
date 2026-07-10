<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\View\View;
use JohnWink\FilamentLeadPipeline\Concerns\ScopesOperationsLeads;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class AdvisorScorecardPanel extends Component
{
    use ScopesOperationsLeads;

    /** Nur serverseitig via open()/close() mutiert — nie per wire:model. #[Locked] lehnt geforgte Client-Updates ab. */
    #[Locked]
    public bool $isOpen = false;

    #[Locked]
    public ?string $advisorId = null;

    public ?string $boardId = null;

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public string $preset = '30';

    public int $shown = 50;

    #[On('open-advisor-scorecard')]
    public function open(string $advisorId): void
    {
        if ( ! $this->isOperationsLeadership($this->boardId) && $advisorId !== (string) auth()->id()) {
            abort(403);
        }

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
        // Defense-in-depth zusätzlich zu #[Locked] und dem open()-Guard: erreicht
        // eine fremde advisorId render() trotzdem (z. B. via serverseitiger
        // Mount-Zuweisung), wird sie auf self zurückgesetzt statt zu werfen —
        // render() darf nie abbrechen müssen; Self-Heal wie im Page-Muster
        // (LeadOperations::getViewData()).
        if (null !== $this->advisorId && ! $this->isOperationsLeadership($this->boardId) && $this->advisorId !== (string) auth()->id()) {
            $this->advisorId = (string) auth()->id();
        }

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
