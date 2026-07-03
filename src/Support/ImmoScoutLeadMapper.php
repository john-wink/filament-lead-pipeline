<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Support;

use Carbon\Carbon;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;

/**
 * Maps an ImmoScout24 construction financing lead onto LeadData.
 *
 * Enum values are materialized as German data values on import (the source
 * platform and its leads are German); unknown enum values pass through raw so
 * new upstream values never break the import. Field NAMES are resolved via
 * translation keys when the definitions are created.
 */
class ImmoScoutLeadMapper
{
    private const APPOINTMENT_TIMEZONE = 'Europe/Berlin';

    private const NUMBER_WORDS = [
        'FIVE'     => 5, 'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10, 'ELEVEN' => 11, 'TWELVE' => 12,
        'THIRTEEN' => 13, 'FOURTEEN' => 14, 'FIFTEEN' => 15, 'SIXTEEN' => 16, 'SEVENTEEN' => 17,
        'EIGHTEEN' => 18, 'NINETEEN' => 19, 'TWENTY' => 20, 'TWENTYONE' => 21, 'TWENTYFIVE' => 25,
        'THIRTY'   => 30, 'THIRTYFIVE' => 35, 'FORTY' => 40,
    ];

    private const VALUE_LABELS = [
        'request_type' => [
            'APPOINTMENT_REQUEST' => 'Terminanfrage',
            'FINANCING_REQUEST'   => 'Finanzierungsanfrage',
            'LOCAL_LENDER'        => 'Regionale Anfrage',
        ],
        'financing_type' => [
            'BuildingFinancing' => 'Neubaufinanzierung',
            'PurchaseFinancing' => 'Kauffinanzierung',
            'FollowupFinancing' => 'Anschlussfinanzierung',
        ],
        'employment' => [
            'CIVIL_SERVANT' => 'Verbeamtet',
            'EMPLOYED'      => 'Angestellt',
            'FREELANCER'    => 'Freiberuflich',
            'SELF_EMPLOYED' => 'Selbstständig',
            'WORKER'        => 'Arbeiter/in',
            'PENSIONER'     => 'Rentner/in',
            'UNEMPLOYED'    => 'Ohne Beschäftigung',
            'STUDENT'       => 'Student/in',
        ],
        'salutation' => [
            'MR'  => 'Herr',
            'MRS' => 'Frau',
        ],
        'contact_channel' => [
            'TELEPHONE'                   => 'Telefon',
            'EMAIL'                       => 'E-Mail',
            'MEETING_AT_PROVIDERS_PLACE'  => 'Termin beim Anbieter',
            'MEETING_AT_REQUESTERS_PLACE' => 'Termin beim Interessenten',
        ],
        'use_type' => [
            'OWNER_OCCUPATION'  => 'Eigennutzung',
            'PARTIALLY_RENTING' => 'Teilvermietung',
            'RENTING'           => 'Vermietung',
        ],
        'financing_start' => [
            'AS_FAST_AS_POSSIBLE'             => 'Schnellstmöglich',
            'IN_LESS_THAN_THREE_MONTHS'       => 'In weniger als 3 Monaten',
            'IN_BETWEEN_THREE_AND_SIX_MONTHS' => 'In 3–6 Monaten',
            'IN_LESS_THAN_ONE_YEAR'           => 'In weniger als einem Jahr',
            'IN_MORE_THAN_ONE_YEAR'           => 'In mehr als einem Jahr',
        ],
        'project_state' => [
            'SEARCHING'           => 'Auf Objektsuche',
            'REAL_ESTATE_VISITED' => 'Objekt besichtigt',
            'PURCHASE_INTENDED'   => 'Kauf beabsichtigt',
            'PURCHASE_AGREED'     => 'Kauf vereinbart',
        ],
    ];

    /** @return array<string, array{name: string, type: LeadFieldTypeEnum, show_in_card: bool}> */
    public static function fieldDefinitions(): array
    {
        $definition = fn (string $key, LeadFieldTypeEnum $type, bool $showInCard = false): array => [
            'name'         => __('lead-pipeline::lead-pipeline.immoscout.fields.' . $key),
            'type'         => $type,
            'show_in_card' => $showInCard,
        ];

        return [
            'is24_request_type'        => $definition('request_type', LeadFieldTypeEnum::String, true),
            'is24_financing_type'      => $definition('financing_type', LeadFieldTypeEnum::String, true),
            'is24_purchase_price'      => $definition('purchase_price', LeadFieldTypeEnum::Currency, true),
            'is24_own_funds'           => $definition('own_funds', LeadFieldTypeEnum::Currency),
            'is24_additional_costs'    => $definition('additional_costs', LeadFieldTypeEnum::Currency),
            'is24_property_value'      => $definition('property_value', LeadFieldTypeEnum::Currency, true),
            'is24_remaining_debt'      => $definition('remaining_debt', LeadFieldTypeEnum::Currency),
            'is24_financing_start'     => $definition('financing_start', LeadFieldTypeEnum::String),
            'is24_project_state'       => $definition('project_state', LeadFieldTypeEnum::String),
            'is24_amortization_rate'   => $definition('amortization_rate', LeadFieldTypeEnum::Decimal),
            'is24_fixed_interest'      => $definition('fixed_interest', LeadFieldTypeEnum::String),
            'is24_employment'          => $definition('employment', LeadFieldTypeEnum::String),
            'is24_use_type'            => $definition('use_type', LeadFieldTypeEnum::String),
            'is24_object_location'     => $definition('object_location', LeadFieldTypeEnum::String),
            'is24_net_income'          => $definition('net_income', LeadFieldTypeEnum::Currency),
            'is24_number_of_borrowers' => $definition('number_of_borrowers', LeadFieldTypeEnum::Number),
            'is24_current_credits'     => $definition('current_credits', LeadFieldTypeEnum::Currency),
            'is24_bank'                => $definition('bank', LeadFieldTypeEnum::String),
            'is24_message'             => $definition('message', LeadFieldTypeEnum::Textarea),
            'is24_contact_address'     => $definition('contact_address', LeadFieldTypeEnum::String),
            'is24_date_of_birth'       => $definition('date_of_birth', LeadFieldTypeEnum::Date),
            'is24_salutation'          => $definition('salutation', LeadFieldTypeEnum::String),
            'is24_contact_channel'     => $definition('contact_channel', LeadFieldTypeEnum::String),
            'is24_availability'        => $definition('availability', LeadFieldTypeEnum::String),
            'is24_appointment_1'       => $definition('appointment_1', LeadFieldTypeEnum::String, true),
            'is24_appointment_2'       => $definition('appointment_2', LeadFieldTypeEnum::String),
            'is24_real_estate'         => $definition('real_estate', LeadFieldTypeEnum::String),
            'is24_expose_url'          => $definition('expose_url', LeadFieldTypeEnum::Url),
            'is24_segment'             => $definition('segment', LeadFieldTypeEnum::String),
        ];
    }

    /**
     * Resolves (or idempotently creates) the board field definition for a
     * mapped custom-field key, restoring a soft-deleted definition instead of
     * duplicating it.
     */
    public static function resolveDefinition(LeadBoard $board, string $key): ?LeadFieldDefinition
    {
        $meta = self::fieldDefinitions()[$key] ?? null;

        if (null === $meta) {
            return null;
        }

        $definition = LeadFieldDefinition::withTrashed()
            ->where(LeadFieldDefinition::fkColumn('lead_board'), $board->getKey())
            ->where('key', $key)
            ->first();

        if ($definition) {
            if ($definition->trashed()) {
                $definition->restore();
            }

            return $definition;
        }

        $sort = (int) LeadFieldDefinition::query()
            ->where(LeadFieldDefinition::fkColumn('lead_board'), $board->getKey())
            ->max('sort');

        return LeadFieldDefinition::query()->create([
            LeadFieldDefinition::fkColumn('lead_board') => $board->getKey(),
            'key'                                       => $key,
            'name'                                      => $meta['name'],
            'type'                                      => $meta['type'],
            'is_system'                                 => false,
            'show_in_card'                              => $meta['show_in_card'],
            'sort'                                      => $sort + 1,
        ]);
    }

    public function map(array $lead): LeadData
    {
        $contact   = $lead['contactRequest'] ?? [];
        $terms     = $contact['financingTerms'] ?? [];
        $financing = $terms['financing'] ?? [];

        $fields = [];
        $put    = function (string $key, mixed $value) use (&$fields): void {
            if (null === $value || '' === $value || 'UNKNOWN' === $value) {
                return;
            }

            $fields[$key] = $value;
        };

        $put('is24_request_type', $this->label('request_type', $lead['requestType'] ?? null));
        $put('is24_financing_type', $this->label('financing_type', $financing['type'] ?? null));
        $put('is24_purchase_price', $financing['purchasePrice'] ?? null);
        $put('is24_own_funds', $financing['ownFunds'] ?? null);
        $put('is24_additional_costs', $financing['additionalCosts'] ?? null);
        $put('is24_property_value', $financing['propertyValue'] ?? null);
        $put('is24_remaining_debt', $financing['remainingDebt'] ?? null);
        $put('is24_financing_start', $this->label('financing_start', $financing['financingStart'] ?? null));
        $put('is24_project_state', $this->label('project_state', $financing['projectState'] ?? null));
        $put('is24_amortization_rate', $terms['amortizationRate'] ?? null);
        $put('is24_fixed_interest', $this->yearsLabel($terms['fixedNominalInterestRate'] ?? null));
        $put('is24_employment', $this->label('employment', $terms['employment'] ?? null));
        $put('is24_use_type', $this->label('use_type', $contact['useType'] ?? null));
        $put('is24_object_location', mb_trim(sprintf('%s %s', $terms['postalCode'] ?? '', $terms['locationName'] ?? '')));
        $put('is24_net_income', $contact['netIncome'] ?? null);
        $put('is24_number_of_borrowers', $contact['numberOfBorrowers'] ?? null);
        $put('is24_current_credits', $contact['currentCredits'] ?? null);
        $put('is24_bank', $contact['bank'] ?? null);
        $put('is24_message', $contact['message'] ?? null);
        $put('is24_contact_address', $this->address($contact['contactAddress'] ?? []));
        $put('is24_date_of_birth', $contact['dateOfBirth'] ?? null);
        $put('is24_salutation', $this->label('salutation', $contact['salutation'] ?? null));
        $put('is24_contact_channel', $this->label('contact_channel', $contact['contactChannel'] ?? null));
        $put('is24_availability', $this->timeWindowLabel($contact['availability'] ?? null));
        $put('is24_appointment_1', $this->appointment($contact['primaryAppointmentDate'] ?? null, $contact['primaryAppointmentTime'] ?? null));
        $put('is24_appointment_2', $this->appointment($contact['secondaryAppointmentDate'] ?? null, $contact['secondaryAppointmentTime'] ?? null));
        $put('is24_real_estate', $lead['realEstate']['title'] ?? null);
        $put('is24_expose_url', $this->exposeUrl($contact['exposeId'] ?? null));
        $put('is24_segment', $contact['segmentClass'] ?? null);

        $value = $financing['purchasePrice'] ?? $financing['propertyValue'] ?? null;

        return new LeadData(
            name: mb_trim(sprintf('%s %s', $contact['forename'] ?? '', $contact['surname'] ?? '')),
            email: $contact['email'] ?? null,
            phone: $contact['phoneNumber'] ?? null,
            custom_fields: $fields,
            source_driver: 'immoscout24',
            value: null !== $value ? (float) $value : null,
        );
    }

    private function label(string $group, ?string $value): ?string
    {
        if (null === $value || '' === $value || 'UNKNOWN' === $value) {
            return null;
        }

        return self::VALUE_LABELS[$group][$value] ?? $value;
    }

    private function yearsLabel(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $word = str_replace('_YEARS', '', $value);

        if (isset(self::NUMBER_WORDS[$word])) {
            return self::NUMBER_WORDS[$word] . ' Jahre';
        }

        return $value;
    }

    private function timeWindowLabel(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if ('ALL_DAY' === $value) {
            return 'Ganztägig';
        }

        $parts = preg_split('/_UNTIL_|_TO_/', $value);

        if (2 === count($parts) && isset(self::NUMBER_WORDS[$parts[0]], self::NUMBER_WORDS[$parts[1]])) {
            return sprintf('%d–%d Uhr', self::NUMBER_WORDS[$parts[0]], self::NUMBER_WORDS[$parts[1]]);
        }

        return $value;
    }

    private function appointment(mixed $timestampMs, ?string $timeWindow): ?string
    {
        if (null === $timestampMs) {
            return null;
        }

        $date  = Carbon::createFromTimestampMs((int) $timestampMs, self::APPOINTMENT_TIMEZONE)->format('d.m.Y');
        $label = $this->timeWindowLabel($timeWindow);

        return null !== $label ? sprintf('%s, %s', $date, $label) : $date;
    }

    /** @param array<string, mixed> $address */
    private function address(array $address): ?string
    {
        $street = mb_trim(sprintf('%s %s', $address['street'] ?? '', $address['streetNumber'] ?? ''));
        $city   = mb_trim(sprintf('%s %s', $address['postalCode'] ?? '', $address['location'] ?? ''));

        $parts = array_filter([$street, $city], fn (string $part): bool => '' !== $part);

        return [] === $parts ? null : implode(', ', $parts);
    }

    private function exposeUrl(mixed $exposeId): ?string
    {
        if (null === $exposeId || '' === $exposeId) {
            return null;
        }

        return 'https://www.immobilienscout24.de/expose/' . $exposeId;
    }
}
