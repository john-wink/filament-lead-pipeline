<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JohnWink\FilamentLeadPipeline\Contracts\LeadConverter;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use RuntimeException;

class LeadConversionService
{
    /** @var array<string, LeadConverter> */
    protected array $converters = [];

    public function registerConverter(string $name, LeadConverter $converter): void
    {
        $this->converters[$name] = $converter;
    }

    public function getConverter(string $name): LeadConverter
    {
        if ( ! isset($this->converters[$name])) {
            throw new InvalidArgumentException(
                sprintf('Lead converter [%s] is not registered.', $name),
            );
        }

        return $this->converters[$name];
    }

    /** @return array<string, LeadConverter> */
    public function getAvailableConverters(): array
    {
        return $this->converters;
    }

    public function convert(Lead $lead, string $converterName, array $additionalData = []): Model
    {
        $converter = $this->getConverter($converterName);

        $validationErrors = $converter->validate($lead);

        if ( ! empty($validationErrors)) {
            throw new RuntimeException(
                sprintf(
                    'Lead validation failed: %s',
                    implode(', ', $validationErrors),
                ),
            );
        }

        $convertedModel = $converter->convert($lead, $additionalData);

        $lead->conversions()->create([
            'convertible_type' => $convertedModel->getMorphClass(),
            'convertible_id'   => $convertedModel->getKey(),
            'converter_class'  => $converter::class,
            'metadata'         => $additionalData,
        ]);

        $lead->update([
            'status'       => LeadStatusEnum::Converted,
            'converted_at' => now(),
        ]);

        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Converted,
            'description' => sprintf(
                __('lead-pipeline::lead-pipeline.activity.converted_with'),
                $converter->getDisplayName(),
                class_basename($convertedModel),
                $convertedModel->getKey(),
            ),
            'properties' => [
                'converter'            => $converterName,
                'converter_class'      => $converter::class,
                'converted_model_type' => $convertedModel->getMorphClass(),
                'converted_model_id'   => $convertedModel->getKey(),
            ],
        ]);

        event(new \JohnWink\FilamentLeadPipeline\Events\LeadConverted($lead, $convertedModel));

        return $convertedModel;
    }
}
