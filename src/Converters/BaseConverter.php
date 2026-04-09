<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Converters;

use JohnWink\FilamentLeadPipeline\Contracts\LeadConverter;
use JohnWink\FilamentLeadPipeline\Models\Lead;

abstract class BaseConverter implements LeadConverter
{
    /** @return array<string> */
    public function validate(Lead $lead): array
    {
        $errors = [];

        if (empty($lead->name)) {
            $errors[] = 'Lead hat keinen Namen.';
        }

        if (empty($lead->email) && empty($lead->phone)) {
            $errors[] = 'Lead hat weder E-Mail noch Telefon.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $additionalData
     * @return array<string, mixed>
     */
    protected function mapLeadToAttributes(Lead $lead, array $additionalData = []): array
    {
        return array_merge([
            'name'  => $lead->name,
            'email' => $lead->email,
        ], $additionalData);
    }
}
