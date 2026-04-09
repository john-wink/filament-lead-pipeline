<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\RawJs;

enum LeadFieldTypeEnum: string implements HasLabel
{
    case String      = 'string';
    case Email       = 'email';
    case Phone       = 'phone';
    case Number      = 'number';
    case Decimal     = 'decimal';
    case Currency    = 'currency';
    case Boolean     = 'boolean';
    case Date        = 'date';
    case Select      = 'select';
    case MultiSelect = 'multi_select';
    case Textarea    = 'textarea';
    case Url         = 'url';

    public function getLabel(): string
    {
        return match ($this) {
            self::String      => __('lead-pipeline::lead-pipeline.lead_field_type.string'),
            self::Email       => __('lead-pipeline::lead-pipeline.lead_field_type.email'),
            self::Phone       => __('lead-pipeline::lead-pipeline.lead_field_type.phone'),
            self::Number      => __('lead-pipeline::lead-pipeline.lead_field_type.number'),
            self::Decimal     => __('lead-pipeline::lead-pipeline.lead_field_type.decimal'),
            self::Currency    => __('lead-pipeline::lead-pipeline.lead_field_type.currency'),
            self::Boolean     => __('lead-pipeline::lead-pipeline.lead_field_type.boolean'),
            self::Date        => __('lead-pipeline::lead-pipeline.lead_field_type.date'),
            self::Select      => __('lead-pipeline::lead-pipeline.lead_field_type.select'),
            self::MultiSelect => __('lead-pipeline::lead-pipeline.lead_field_type.multi_select'),
            self::Textarea    => __('lead-pipeline::lead-pipeline.lead_field_type.textarea'),
            self::Url         => __('lead-pipeline::lead-pipeline.lead_field_type.url'),
        };
    }

    public function toFormComponent(string $key, ?array $options = null): Component
    {
        return match ($this) {
            self::String      => TextInput::make($key)->maxLength(255),
            self::Email       => TextInput::make($key)->email()->maxLength(255),
            self::Phone       => TextInput::make($key)->tel()->maxLength(50),
            self::Number      => TextInput::make($key)->numeric()->integer(),
            self::Decimal     => TextInput::make($key)->numeric()->inputMode('decimal')->step('0.01'),
            self::Currency    => TextInput::make($key)->mask(RawJs::make("$money($input, ',', '.')")),
            self::Boolean     => Checkbox::make($key),
            self::Date        => DatePicker::make($key),
            self::Select      => Select::make($key)->options($options ?? []),
            self::MultiSelect => Select::make($key)->multiple()->options($options ?? []),
            self::Textarea    => Textarea::make($key)->rows(3),
            self::Url         => TextInput::make($key)->url()->maxLength(2048),
        };
    }

    public function castValue(mixed $value): mixed
    {
        return match ($this) {
            self::Number => (int) $value,
            self::Decimal, self::Currency => (float) $value,
            self::Boolean     => (bool) $value,
            self::MultiSelect => is_array($value) ? $value : json_decode((string) $value, true),
            default           => (string) $value,
        };
    }

    /** @return array<string> */
    public function validationRules(): array
    {
        return match ($this) {
            self::String => ['string', 'max:255'],
            self::Email  => ['email', 'max:255'],
            self::Phone  => ['string', 'max:50'],
            self::Number => ['integer'],
            self::Decimal, self::Currency => ['numeric'],
            self::Boolean     => ['boolean'],
            self::Date        => ['date'],
            self::Select      => ['string'],
            self::MultiSelect => ['array'],
            self::Textarea    => ['string', 'max:65535'],
            self::Url         => ['url', 'max:2048'],
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect]);
    }
}
