<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadAssigned;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Attributes\On;
use Livewire\Component;

class PhaseListTable extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public string $phaseId;

    /** @var array<string, mixed> */
    public array $filters = [];

    public function mount(string $phaseId): void
    {
        $this->phaseId = $phaseId;
        $this->filters = session('lead-pipeline.filters.' . LeadPhase::find($phaseId)?->board?->getKey(), []);
    }

    #[On('filters-updated')]
    public function applyFilters(array $filters): void
    {
        $this->filters = $filters;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $phaseFk = LeadPhase::fkColumn('lead_phase');
        $filters = $this->filters;

        return $table
            ->query(function () use ($phaseFk, $filters) {
                $query = Lead::query()->where($phaseFk, $this->phaseId);
                $phase = LeadPhase::find($this->phaseId);

                if ($phase) {
                    $board = $phase->board;
                    $user  = auth()->user();
                    if ($board && $user) {
                        $query->visibleTo($user, $board);
                    }
                }

                // Apply page-level filters
                if (filled($filters['source_id'] ?? null)) {
                    $query->where(Lead::fkColumn('lead_source'), $filters['source_id']);
                }
                if (filled($filters['assigned_to'] ?? null)) {
                    $query->where('assigned_to', $filters['assigned_to']);
                }
                if (filled($filters['status'] ?? null)) {
                    $query->where('status', $filters['status']);
                }
                if (filled($filters['value_min'] ?? null)) {
                    $query->where('value', '>=', $filters['value_min']);
                }
                if (filled($filters['value_max'] ?? null)) {
                    $query->where('value', '<=', $filters['value_max']);
                }
                if (filled($filters['created_from'] ?? null)) {
                    $query->whereDate('created_at', '>=', $filters['created_from']);
                }
                if (filled($filters['created_to'] ?? null)) {
                    $query->whereDate('created_at', '<=', $filters['created_to']);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('name')
                    ->label(__('lead-pipeline::lead-pipeline.field.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('lead-pipeline::lead-pipeline.field.email'))
                    ->searchable(),
                TextColumn::make('phone')
                    ->label(__('lead-pipeline::lead-pipeline.field.phone')),
                TextColumn::make('value')
                    ->label(__('lead-pipeline::lead-pipeline.field.value'))
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('source.name')
                    ->label(__('lead-pipeline::lead-pipeline.field.source')),
                TextColumn::make('assignedUser.name')
                    ->label(__('lead-pipeline::lead-pipeline.field.assigned_to')),
                TextColumn::make('updated_at')
                    ->label(__('lead-pipeline::lead-pipeline.activity.updated'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view_detail')
                    ->label('')
                    ->tooltip(__('lead-pipeline::lead-pipeline.actions.view_details'))
                    ->icon('heroicon-o-eye')
                    ->action(fn (Lead $record) => $this->dispatch('open-lead-detail', leadId: $record->getKey())),
                Tables\Actions\Action::make('assign')
                    ->label('')
                    ->tooltip(__('lead-pipeline::lead-pipeline.actions.assign_advisor'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (Lead $record) => ! $record->assigned_to)
                    ->form([
                        Forms\Components\Select::make('assigned_to')
                            ->label(__('lead-pipeline::lead-pipeline.actions.advisor'))
                            ->options(fn () => FilamentLeadPipelinePlugin::getAssignableUsers()
                                ->pluck('display_label', 'uuid' === config('lead-pipeline.primary_key_type') ? 'uuid' : 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Lead $record, array $data): void {
                        $record->update(['assigned_to' => $data['assigned_to']]);

                        $assigneeName = config('lead-pipeline.user_model')::find($data['assigned_to'])?->name ?? __('lead-pipeline::lead-pipeline.field.unknown');

                        $record->activities()->create([
                            'type'        => LeadActivityTypeEnum::Assignment->value,
                            'description' => __('lead-pipeline::lead-pipeline.actions.assigned_to_name', ['name' => $assigneeName]),
                            'causer_type' => config('lead-pipeline.user_model'),
                            'causer_id'   => auth()->id(),
                        ]);

                        LeadAssigned::dispatch(
                            $record,
                            config('lead-pipeline.user_model')::find($data['assigned_to']),
                            auth()->user(),
                        );

                        // Auto-move from Open to first InProgress
                        $phase = $record->phase;
                        if ($phase && LeadPhaseTypeEnum::Open === $phase->type) {
                            $firstInProgress = $record->board->phases()
                                ->where('type', LeadPhaseTypeEnum::InProgress)
                                ->ordered()
                                ->first();

                            if ($firstInProgress) {
                                $record->moveToPhase($firstInProgress);
                            }
                        }
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function render(): View
    {
        return view('lead-pipeline::kanban.phase-list-table');
    }
}
