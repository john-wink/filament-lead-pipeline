<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;

class CreateLeadBoard extends CreateRecord
{
    protected static string $resource = LeadBoardResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['phases'], $data['fieldDefinitions']);

        return $data;
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = $this->getDefaultData();

        $this->form->fill($data);

        $this->callHook('afterFill');
    }

    /** @return array<string, mixed> */
    protected function getDefaultData(): array
    {
        $plugin = Filament::getCurrentPanel()?->getPlugin('filament-lead-pipeline');

        if ( ! $plugin instanceof FilamentLeadPipelinePlugin) {
            return [];
        }

        $data = [];

        $defaultPhases = $plugin->getDefaultPhases();
        if ( ! empty($defaultPhases)) {
            $data['phases'] = collect($defaultPhases)->map(fn ($phase, $index) => [
                'name'              => $phase->name,
                'type'              => $phase->type->value,
                'display_type'      => $phase->display_type->value,
                'color'             => $phase->color,
                'sort'              => $index,
                'auto_convert'      => $phase->auto_convert,
                'conversion_target' => $phase->conversion_target,
            ])->toArray();
        }

        $defaultFields = $plugin->getDefaultFields();
        if ( ! empty($defaultFields)) {
            $data['fieldDefinitions'] = collect($defaultFields)->map(fn ($field, $index) => [
                'name'           => $field->name,
                'key'            => $field->key,
                'type'           => $field->type->value,
                'is_required'    => $field->is_required,
                'show_in_card'   => $field->show_in_card,
                'show_in_funnel' => $field->show_in_funnel,
                'options'        => $field->options,
                'sort'           => $index,
            ])->toArray();
        }

        return $data;
    }
}
