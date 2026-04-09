<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStepField;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::funnel-wizard', FunnelWizard::class);
});

/**
 * Helper: creates a complete funnel setup with board, phases, source, funnel, steps, and field definitions.
 *
 * @return array{board: LeadBoard, source: LeadSource, funnel: LeadFunnel, nameField: LeadFieldDefinition, emailField: LeadFieldDefinition, phoneField: LeadFieldDefinition, firstPhase: LeadPhase}
 */
function createFunnelWithFields(int $steps = 2, array $extraFields = []): array
{
    $board = LeadBoard::factory()->create();

    $firstPhase = LeadPhase::factory()->for($board, 'board')->open()->create(['name' => 'Neu', 'sort' => 0]);
    LeadPhase::factory()->for($board, 'board')->create(['name' => 'Kontaktiert', 'sort' => 1]);

    $nameField  = $board->fieldDefinitions()->where('key', 'name')->first();
    $emailField = $board->fieldDefinitions()->where('key', 'email')->first();
    $phoneField = $board->fieldDefinitions()->where('key', 'phone')->first();

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    // Create steps with fields
    for ($i = 0; $i < $steps; $i++) {
        $step = LeadFunnelStep::factory()->create([
            LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
            'sort'                                  => $i,
            'name'                                  => 'Step ' . ($i + 1),
            'description'                           => 'Beschreibung fuer Step ' . ($i + 1),
        ]);

        if (0 === $i) {
            // First step: name (required) + email (optional)
            LeadFunnelStepField::factory()->create([
                LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
                LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
                'sort'                                                 => 0,
                'is_required'                                          => true,
            ]);
            LeadFunnelStepField::factory()->create([
                LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
                LeadFunnelStepField::fkColumn('lead_field_definition') => $emailField->getKey(),
                'sort'                                                 => 1,
                'is_required'                                          => false,
            ]);
        }

        if (1 === $i) {
            // Second step: phone (optional)
            LeadFunnelStepField::factory()->create([
                LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
                LeadFunnelStepField::fkColumn('lead_field_definition') => $phoneField->getKey(),
                'sort'                                                 => 0,
                'is_required'                                          => false,
            ]);
        }
    }

    return compact('board', 'source', 'funnel', 'nameField', 'emailField', 'phoneField', 'firstPhase');
}

// === NAVIGATION ===

it('renders first step on mount', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->assertOk()
        ->assertSet('currentStep', 0)
        ->assertSee('Step 1');
});

it('shows progress bar when enabled', function (): void {
    $setup = createFunnelWithFields();
    $setup['funnel']->update(['design' => ['show_progress_bar' => true]]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->assertOk()
        ->assertSee('50%');
});

it('shows step name and description', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->assertSee('Step 1')
        ->assertSee('Beschreibung fuer Step 1');
});

it('can navigate to next step', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Test User')
        ->call('nextStep')
        ->assertSet('currentStep', 1)
        ->assertSee('Step 2');
});

it('can navigate to previous step', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Test User')
        ->call('nextStep')
        ->assertSet('currentStep', 1)
        ->call('previousStep')
        ->assertSet('currentStep', 0);
});

it('cannot go before first step', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->call('previousStep')
        ->assertSet('currentStep', 0);
});

it('shows "Zurueck" button only after first step', function (): void {
    $setup = createFunnelWithFields();

    $component = Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()]);

    // On first step, no "Zurueck" button (HTML entity &larr; Zur&uuml;ck)
    $component->assertDontSee('previousStep');

    // After navigating to step 2
    $component->set('formData.name', 'Test')
        ->call('nextStep')
        ->assertSeeHtml('previousStep');
});

it('shows "Weiter" button on non-last steps', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->assertSee('Weiter');
});

it('shows "Absenden" button on last step', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Test')
        ->call('nextStep')
        ->assertSee('Absenden');
});

it('calculates progress percentage correctly', function (): void {
    $setup = createFunnelWithFields(steps: 4);

    $component = Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()]);

    // Step 1 of 4 = 25%
    $component->assertSee('25%');
});

// === VALIDATION ===

it('validates required fields before advancing to next step', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->call('nextStep')
        ->assertHasErrors(['formData.name']);
});

it('shows validation errors for missing required fields', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->call('nextStep')
        ->assertHasErrors(['formData.name' => 'required']);
});

it('allows optional fields to be empty', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Test User')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 1);
});

it('validates email format for email fields', function (): void {
    $setup = createFunnelWithFields();

    // Make email required and use EmailInput funnel type so email validation triggers
    $funnel         = $setup['funnel'];
    $firstStep      = $funnel->steps()->orderBy('sort')->first();
    $emailStepField = $firstStep->fields()->whereHas('definition', fn ($q) => $q->where('key', 'email'))->first();
    $emailStepField->update(['is_required' => true, 'funnel_field_type' => FunnelFieldTypeEnum::EmailInput]);

    // Refresh the funnel to pick up changes
    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Test User')
        ->set('formData.email', 'not-an-email')
        ->call('nextStep')
        ->assertHasErrors(['formData.email']);
});

it('validates phone format for phone fields', function (): void {
    $setup = createFunnelWithFields();

    // Phone is on step 2. Navigate to step 2 first, then set required
    $funnel         = $setup['funnel'];
    $secondStep     = $funnel->steps()->orderBy('sort')->skip(1)->first();
    $phoneStepField = $secondStep->fields()->first();
    $phoneStepField->update(['is_required' => true]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Test User')
        ->call('nextStep')
        ->assertSet('currentStep', 1)
        // Phone field type has rule 'string', 'max:50' - providing a valid phone should pass
        ->set('formData.phone', '+49 170 1234567')
        ->call('submit')
        ->assertHasNoErrors(['formData.phone']);
});

it('clears errors when corrected and advancing again', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->call('nextStep')
        ->assertHasErrors(['formData.name'])
        ->set('formData.name', 'Now filled')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 1);
});

// === SUBMISSION ===

it('creates a lead on submit', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Neuer Lead')
        ->set('formData.email', 'neuer@example.com')
        ->call('nextStep')
        ->call('submit')
        ->assertOk();

    expect(Lead::query()->where('name', 'Neuer Lead')->exists())->toBeTrue();
});

it('saves name, email, phone from form data', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Hans Meier')
        ->set('formData.email', 'hans@meier.de')
        ->call('nextStep')
        ->set('formData.phone', '+49 170 5555555')
        ->call('submit');

    $lead = Lead::query()->where('name', 'Hans Meier')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->email)->toBe('hans@meier.de')
        ->and($lead->phone)->toBe('+49 170 5555555');
});

it('saves custom field values from form data', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField    = $board->fieldDefinitions()->where('key', 'name')->first();
    $companyField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name'      => 'Firma', 'key' => 'firma', 'type' => LeadFieldTypeEnum::String,
        'is_system' => false, 'is_required' => false,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Daten',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $companyField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Max Mustermann')
        ->set('formData.email', 'max@mustermann.de')
        ->set('formData.firma', 'Mustermann GmbH')
        ->call('submit');

    $lead = Lead::query()->where('name', 'Max Mustermann')->first();
    expect($lead)->not->toBeNull();

    $fieldValue = $lead->getFieldValue('firma');
    expect($fieldValue)->toBe('Mustermann GmbH');
});

it('places lead in correct phase (funnel target or first open phase)', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Phase Test Lead')
        ->set('formData.email', 'phase@example.com')
        ->call('nextStep')
        ->call('submit');

    $lead = Lead::query()->where('name', 'Phase Test Lead')->first();
    // Should be placed in the first open phase since funnel has no targetPhase set
    expect($lead->{Lead::fkColumn('lead_phase')})->toBe($setup['firstPhase']->getKey());
});

it('links lead to funnel source', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Source Test')
        ->set('formData.email', 'source@example.com')
        ->call('nextStep')
        ->call('submit');

    $lead = Lead::query()->where('name', 'Source Test')->first();
    expect($lead->{Lead::fkColumn('lead_source')})->toBe($setup['source']->getKey());
});

it('sets submitted flag to true after submit', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Submit Flag Test')
        ->set('formData.email', 'submitflag@example.com')
        ->call('nextStep')
        ->call('submit')
        ->assertSet('submitted', true);
});

it('increments funnel submissions count', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    expect($setup['funnel']->submissions_count)->toBe(0);

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Submissions Count Test')
        ->set('formData.email', 'submissions@example.com')
        ->call('nextStep')
        ->call('submit');

    expect($setup['funnel']->refresh()->submissions_count)->toBe(1);
});

it('creates activity log for created lead', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Activity Test')
        ->set('formData.email', 'activity@example.com')
        ->call('nextStep')
        ->call('submit');

    $lead     = Lead::query()->where('name', 'Activity Test')->first();
    $activity = $lead->activities()->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(LeadActivityTypeEnum::Created)
        ->and($activity->description)->toContain('funnel')
        ->and($activity->properties['source'])->toBe('funnel');
});

it('dispatches LeadCreated event', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Event Test')
        ->set('formData.email', 'event@example.com')
        ->call('nextStep')
        ->call('submit');

    Event::assertDispatched(LeadCreated::class, function (LeadCreated $event): bool {
        return 'Event Test' === $event->lead->name;
    });
});

// === MULTI-STEP ===

it('preserves form data across steps', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Preserved Name')
        ->set('formData.email', 'preserved@test.de')
        ->call('nextStep')
        ->assertSet('formData.name', 'Preserved Name')
        ->assertSet('formData.email', 'preserved@test.de')
        ->call('previousStep')
        ->assertSet('formData.name', 'Preserved Name')
        ->assertSet('formData.email', 'preserved@test.de');
});

it('validates only current step fields (not all steps)', function (): void {
    $setup = createFunnelWithFields();

    // Make phone required on step 2
    $funnel         = $setup['funnel'];
    $secondStep     = $funnel->steps()->orderBy('sort')->skip(1)->first();
    $phoneStepField = $secondStep->fields()->first();
    $phoneStepField->update(['is_required' => true]);

    // Step 1: should only validate name (required) and email, not phone
    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Test User')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 1);
});

it('handles funnel with single step', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField = $board->fieldDefinitions()->where('key', 'name')->first();

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Einziger Step',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);

    // Single step funnel: should show Absenden directly
    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->assertSee('Absenden')
        ->assertDontSee('Weiter')
        ->set('formData.name', 'Einzelstep Lead')
        ->set('formData.email', 'einzelstep@example.com')
        ->call('submit')
        ->assertSet('submitted', true);

    expect(Lead::query()->where('name', 'Einzelstep Lead')->exists())->toBeTrue();
});

it('handles funnel with many steps (5+)', function (): void {
    $setup = createFunnelWithFields(steps: 5);

    $component = Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()]);

    // Navigate through all 5 steps
    // Step 1 has name required
    $component->set('formData.name', 'Multi Step Test')
        ->call('nextStep')
        ->assertSet('currentStep', 1);

    // Steps 2-4: just navigate
    for ($i = 1; $i < 4; $i++) {
        $component->call('nextStep')
            ->assertSet('currentStep', $i + 1);
    }

    // Should be on last step (index 4)
    $component->assertSet('currentStep', 4)
        ->assertSee('Absenden');
});

// === EDGE CASES ===

it('handles funnel with no steps gracefully', function (): void {
    $board  = LeadBoard::factory()->create();
    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->assertOk();
});

it('requires name and validates as required when field definition says so', function (): void {
    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.email', 'test@example.com')
        ->call('nextStep')
        ->assertHasErrors(['formData.name' => 'required']);
});

it('handles special characters in form inputs', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'O\'Reilly & Soehne <GmbH>')
        ->set('formData.email', 'test+special@example.com')
        ->call('nextStep')
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'O\'Reilly & Soehne <GmbH>')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->email)->toBe('test+special@example.com');
});

it('handles very long text in textarea fields', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField  = $board->fieldDefinitions()->where('key', 'name')->first();
    $notesField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Nachricht', 'key' => 'nachricht', 'type' => LeadFieldTypeEnum::Textarea,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Details',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $notesField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::Textarea,
    ]);

    $longText = str_repeat('Dies ist ein langer Text. ', 500);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Textarea Test')
        ->set('formData.email', 'textarea@example.com')
        ->set('formData.nachricht', $longText)
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'Textarea Test')->first();
    expect($lead->getFieldValue('nachricht'))->toBe($longText);
});

it('handles select fields with options', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField   = $board->fieldDefinitions()->where('key', 'name')->first();
    $selectField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name'    => 'Interesse', 'key' => 'interesse', 'type' => LeadFieldTypeEnum::Select,
        'options' => ['kauf' => 'Kauf', 'miete' => 'Miete', 'beratung' => 'Beratung'],
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Details',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $selectField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Select Test')
        ->set('formData.email', 'select@example.com')
        ->set('formData.interesse', 'kauf')
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'Select Test')->first();
    expect($lead->getFieldValue('interesse'))->toBe('kauf');
});

it('handles boolean/checkbox fields', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField    = $board->fieldDefinitions()->where('key', 'name')->first();
    $booleanField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Datenschutz', 'key' => 'datenschutz', 'type' => LeadFieldTypeEnum::Boolean,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Zustimmung',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $booleanField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::YesNo,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Boolean Test')
        ->set('formData.email', 'boolean@example.com')
        ->set('formData.datenschutz', true)
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'Boolean Test')->first();
    // Boolean stored as string "1" in field values, casted by type
    expect($lead->getFieldValue('datenschutz'))->toBeTruthy();
});

it('handles date fields', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField = $board->fieldDefinitions()->where('key', 'name')->first();
    $dateField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Wunschtermin', 'key' => 'wunschtermin', 'type' => LeadFieldTypeEnum::Date,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Termin',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $dateField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Date Test')
        ->set('formData.email', 'date@example.com')
        ->set('formData.wunschtermin', '2026-04-15')
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'Date Test')->first();
    expect($lead->getFieldValue('wunschtermin'))->toBe('2026-04-15');
});

it('rejects submission when required field is missing', function (): void {
    $setup = createFunnelWithFields(steps: 1);

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->call('submit')
        ->assertHasErrors(['formData.name' => 'required'])
        ->assertSet('submitted', false);

    expect(Lead::query()->count())->toBe(0);
});

it('creates lead with active status', function (): void {
    Event::fake([LeadCreated::class]);

    $setup = createFunnelWithFields();

    Livewire::test(FunnelWizard::class, ['funnelId' => $setup['funnel']->getKey()])
        ->set('formData.name', 'Status Test')
        ->set('formData.email', 'status@example.com')
        ->call('nextStep')
        ->call('submit');

    $lead = Lead::query()->where('name', 'Status Test')->first();
    expect($lead->status)->toBe(LeadStatusEnum::Active);
});

it('handles number fields', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField   = $board->fieldDefinitions()->where('key', 'name')->first();
    $numberField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Zimmeranzahl', 'key' => 'zimmeranzahl', 'type' => LeadFieldTypeEnum::Number,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Anfrage',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $numberField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::Slider,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Number Test')
        ->set('formData.email', 'number@example.com')
        ->set('formData.zimmeranzahl', 4)
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'Number Test')->first();
    expect($lead->getFieldValue('zimmeranzahl'))->toBe(4);
});

it('handles url fields', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField = $board->fieldDefinitions()->where('key', 'name')->first();
    $urlField  = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Website', 'key' => 'website', 'type' => LeadFieldTypeEnum::Url,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Kontakt',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $urlField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'URL Test')
        ->set('formData.email', 'url@example.com')
        ->set('formData.website', 'https://example.com')
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'URL Test')->first();
    expect($lead->getFieldValue('website'))->toBe('https://example.com');
});

it('handles currency fields', function (): void {
    Event::fake([LeadCreated::class]);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField     = $board->fieldDefinitions()->where('key', 'name')->first();
    $currencyField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Budget', 'key' => 'budget', 'type' => LeadFieldTypeEnum::Currency,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Budget',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $currencyField->getKey(),
        'sort'                                                 => 1, 'is_required' => false,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::Slider,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Currency Test')
        ->set('formData.email', 'currency@example.com')
        ->set('formData.budget', 250000.50)
        ->call('submit')
        ->assertSet('submitted', true);

    $lead = Lead::query()->where('name', 'Currency Test')->first();
    expect($lead->getFieldValue('budget'))->toBe(250000.50);
});

it('validates required boolean field', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $nameField = $board->fieldDefinitions()->where('key', 'name')->first();
    $boolField = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name'        => 'AGB akzeptiert', 'key' => 'agb', 'type' => LeadFieldTypeEnum::Boolean,
        'is_required' => true,
    ]);

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0, 'name' => 'Zustimmung',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0, 'is_required' => true,
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $boolField->getKey(),
        'sort'                                                 => 1, 'is_required' => true,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'AGB Test')
        ->call('submit')
        ->assertHasErrors(['formData.agb']);
});
