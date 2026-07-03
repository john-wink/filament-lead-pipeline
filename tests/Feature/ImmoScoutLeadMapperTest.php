<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Support\ImmoScoutLeadMapper;

function immoscoutFixtureLead(int $index): array
{
    $payload = json_decode(
        (string) file_get_contents(__DIR__ . '/../Fixtures/immoscout/test-leads.json'),
        true,
    );

    return $payload['lead'][$index];
}

it('maps core contact fields from a purchase financing lead', function (): void {
    $data = (new ImmoScoutLeadMapper())->map(immoscoutFixtureLead(0));

    expect($data->name)->toBe('Max Mustermann')
        ->and($data->email)->toBe('max.mustermann@is24.de')
        ->and($data->phone)->toBe('55501456789')
        ->and($data->value)->toBe(595000.0)
        ->and($data->source_driver)->toBe('immoscout24');
});

it('maps purchase financing custom fields with readable german values', function (): void {
    $fields = (new ImmoScoutLeadMapper())->map(immoscoutFixtureLead(0))->custom_fields;

    expect($fields['is24_request_type'])->toBe('Terminanfrage')
        ->and($fields['is24_financing_type'])->toBe('Kauffinanzierung')
        ->and($fields['is24_purchase_price'])->toBe(595000.0)
        ->and($fields['is24_own_funds'])->toBe(45000.0)
        ->and($fields['is24_additional_costs'])->toBe(6000.0)
        ->and($fields['is24_amortization_rate'])->toBe(2.0)
        ->and($fields['is24_fixed_interest'])->toBe('10 Jahre')
        ->and($fields['is24_employment'])->toBe('Angestellt')
        ->and($fields['is24_object_location'])->toBe('10243 Berlin-Friedrichshain (Friedrichshain)')
        ->and($fields['is24_message'])->toBe('Bitte um Rückruf.')
        ->and($fields['is24_contact_address'])->toBe('Schlossallee 1, 13245 Berlin')
        ->and($fields['is24_number_of_borrowers'])->toBe(2)
        ->and($fields['is24_bank'])->toBe('Meine Hausbank')
        ->and($fields['is24_availability'])->toBe('Ganztägig');
});

it('maps followup financing fields, appointments and the linked expose', function (): void {
    $data   = (new ImmoScoutLeadMapper())->map(immoscoutFixtureLead(1));
    $fields = $data->custom_fields;

    expect($data->value)->toBe(545000.0)
        ->and($fields['is24_financing_type'])->toBe('Anschlussfinanzierung')
        ->and($fields['is24_property_value'])->toBe(545000.0)
        ->and($fields['is24_remaining_debt'])->toBe(250000.0)
        ->and($fields['is24_current_credits'])->toBe(75000)
        ->and($fields['is24_employment'])->toBe('Freiberuflich')
        ->and($fields)->not->toHaveKey('is24_purchase_price')
        ->and($fields['is24_appointment_1'])->toBe('03.07.2026, 18–19 Uhr')
        ->and($fields['is24_appointment_2'])->toBe('08.07.2026, 11–12 Uhr')
        ->and($fields['is24_real_estate'])->toBe('Großzügiges Landhaus mit Alpenblick')
        ->and($fields['is24_expose_url'])->toBe('https://www.immobilienscout24.de/expose/29648095')
        ->and($fields['is24_segment'])->toBe('PREMIUM_M');
});

it('skips empty values instead of writing blanks', function (): void {
    $fields = (new ImmoScoutLeadMapper())->map(immoscoutFixtureLead(1))->custom_fields;

    // Lead 2 has an empty message and no availability
    expect($fields)->not->toHaveKey('is24_message')
        ->and($fields)->not->toHaveKey('is24_availability');
});

it('falls back to raw enum values it does not know', function (): void {
    $lead = immoscoutFixtureLead(0);

    $lead['contactRequest']['financingTerms']['employment'] = 'SOME_FUTURE_VALUE';

    $fields = (new ImmoScoutLeadMapper())->map($lead)->custom_fields;

    expect($fields['is24_employment'])->toBe('SOME_FUTURE_VALUE');
});

it('provides field definitions for every mapped custom field', function (): void {
    $mapper      = new ImmoScoutLeadMapper();
    $definitions = ImmoScoutLeadMapper::fieldDefinitions();

    foreach ([0, 1] as $index) {
        $fields = $mapper->map(immoscoutFixtureLead($index))->custom_fields;

        foreach (array_keys($fields) as $key) {
            expect($definitions)->toHaveKey($key);
        }
    }

    expect($definitions['is24_purchase_price']['type'])->toBe(LeadFieldTypeEnum::Currency)
        ->and($definitions['is24_expose_url']['type'])->toBe(LeadFieldTypeEnum::Url)
        ->and($definitions['is24_message']['type'])->toBe(LeadFieldTypeEnum::Textarea)
        ->and($definitions['is24_financing_type']['name'])->not->toBeEmpty();
});
