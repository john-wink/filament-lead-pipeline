<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Contracts\LeadConverter;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadConversionService;
use Livewire\Livewire;

class AutoConversionFakeConverter implements LeadConverter
{
    /** @var array<string> */
    public array $validationErrors = [];

    public function getDisplayName(): string
    {
        return 'Fake Converter';
    }

    public function getTargetModelClass(): string
    {
        return LeadBoard::class;
    }

    public function getConversionFormSchema(Lead $lead): array
    {
        return [];
    }

    public function convert(Lead $lead, array $additionalData = []): Model
    {
        return $lead->board;
    }

    public function validate(Lead $lead, array $additionalData = []): array
    {
        return $this->validationErrors;
    }
}

beforeEach(function (): void {
    $this->user = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->actingAs($this->user);

    $board      = LeadBoard::factory()->create();
    $phase      = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $this->lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);
});

function markLeadAsWon(Lead $lead): void
{
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsWon');
}

it('auto converts a won lead when enabled and exactly one converter exists', function (): void {
    config()->set('lead-pipeline.conversion.auto_convert_on_won', true);
    app(LeadConversionService::class)->registerConverter('fake', new AutoConversionFakeConverter());

    markLeadAsWon($this->lead);

    $this->lead->refresh();

    expect($this->lead->status)->toBe(LeadStatusEnum::Converted)
        ->and($this->lead->conversions()->count())->toBe(1)
        ->and($this->lead->converted_at)->not->toBeNull();
});

it('does nothing when the feature is disabled', function (): void {
    config()->set('lead-pipeline.conversion.auto_convert_on_won', false);
    app(LeadConversionService::class)->registerConverter('fake', new AutoConversionFakeConverter());

    markLeadAsWon($this->lead);

    expect($this->lead->refresh()->status)->toBe(LeadStatusEnum::Won)
        ->and($this->lead->conversions()->count())->toBe(0);
});

it('does not auto convert when several converters are registered', function (): void {
    config()->set('lead-pipeline.conversion.auto_convert_on_won', true);
    app(LeadConversionService::class)->registerConverter('fake_a', new AutoConversionFakeConverter());
    app(LeadConversionService::class)->registerConverter('fake_b', new AutoConversionFakeConverter());

    markLeadAsWon($this->lead);

    expect($this->lead->refresh()->status)->toBe(LeadStatusEnum::Won)
        ->and($this->lead->conversions()->count())->toBe(0);
});

it('logs a transparent activity when the auto conversion fails', function (): void {
    config()->set('lead-pipeline.conversion.auto_convert_on_won', true);
    $converter                   = new AutoConversionFakeConverter();
    $converter->validationErrors = ['Telefonnummer fehlt'];
    app(LeadConversionService::class)->registerConverter('fake', $converter);

    markLeadAsWon($this->lead);

    expect($this->lead->refresh()->status)->toBe(LeadStatusEnum::Won)
        ->and($this->lead->conversions()->count())->toBe(0)
        ->and($this->lead->activities()->where('description', 'like', '%Telefonnummer fehlt%')->exists())->toBeTrue();
});

it('skips leads that already have a conversion', function (): void {
    config()->set('lead-pipeline.conversion.auto_convert_on_won', true);
    app(LeadConversionService::class)->registerConverter('fake', new AutoConversionFakeConverter());

    $this->lead->conversions()->create([
        'convertible_type' => 'lead_board',
        'convertible_id'   => $this->lead->board->getKey(),
        'converter_class'  => AutoConversionFakeConverter::class,
        'metadata'         => [],
    ]);

    markLeadAsWon($this->lead);

    expect($this->lead->conversions()->count())->toBe(1)
        ->and($this->lead->refresh()->status)->toBe(LeadStatusEnum::Won);
});
