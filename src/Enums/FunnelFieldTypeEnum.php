<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasLabel;

enum FunnelFieldTypeEnum: string implements HasLabel
{
    case TextInput        = 'text_input';
    case EmailInput       = 'email_input';
    case PhoneInput       = 'phone_input';
    case Textarea         = 'textarea';
    case OptionCards      = 'option_cards';
    case MultiOptionCards = 'multi_option_cards';
    case YesNo            = 'yes_no';
    case Slider           = 'slider';
    case IconCards        = 'icon_cards';
    case DatePicker       = 'date_picker';

    /** @return array<self> */
    public static function allowedFor(LeadFieldTypeEnum $type): array
    {
        return match ($type) {
            LeadFieldTypeEnum::String => [self::TextInput, self::OptionCards, self::MultiOptionCards],
            LeadFieldTypeEnum::Email  => [self::EmailInput],
            LeadFieldTypeEnum::Phone  => [self::PhoneInput],
            LeadFieldTypeEnum::Number,
            LeadFieldTypeEnum::Decimal,
            LeadFieldTypeEnum::Currency    => [self::TextInput, self::OptionCards, self::Slider],
            LeadFieldTypeEnum::Boolean     => [self::YesNo],
            LeadFieldTypeEnum::Date        => [self::DatePicker],
            LeadFieldTypeEnum::Select      => [self::OptionCards, self::IconCards],
            LeadFieldTypeEnum::MultiSelect => [self::MultiOptionCards, self::IconCards],
            LeadFieldTypeEnum::Textarea    => [self::Textarea],
            LeadFieldTypeEnum::Url         => [self::TextInput],
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::TextInput        => __('lead-pipeline::lead-pipeline.funnel_field_type.text_input'),
            self::EmailInput       => __('lead-pipeline::lead-pipeline.funnel_field_type.email_input'),
            self::PhoneInput       => __('lead-pipeline::lead-pipeline.funnel_field_type.phone_input'),
            self::Textarea         => __('lead-pipeline::lead-pipeline.funnel_field_type.textarea'),
            self::OptionCards      => __('lead-pipeline::lead-pipeline.funnel_field_type.option_cards'),
            self::MultiOptionCards => __('lead-pipeline::lead-pipeline.funnel_field_type.multi_option_cards'),
            self::YesNo            => __('lead-pipeline::lead-pipeline.funnel_field_type.yes_no'),
            self::Slider           => __('lead-pipeline::lead-pipeline.funnel_field_type.slider'),
            self::IconCards        => __('lead-pipeline::lead-pipeline.funnel_field_type.icon_cards'),
            self::DatePicker       => __('lead-pipeline::lead-pipeline.funnel_field_type.date_picker'),
        };
    }

    public function renderView(): string
    {
        $slug = str_replace('_', '-', $this->value);

        return "lead-pipeline::funnel.fields.{$slug}";
    }

    /** @return array<string> */
    public function validationRules(): array
    {
        return match ($this) {
            self::TextInput, self::PhoneInput => ['string', 'max:255'],
            self::EmailInput => ['email', 'max:255'],
            self::Textarea   => ['string', 'max:65535'],
            self::OptionCards, self::IconCards => ['string'],
            self::MultiOptionCards => ['array'],
            self::YesNo            => ['boolean'],
            self::Slider           => ['numeric'],
            self::DatePicker       => ['date'],
        };
    }

    public function castValue(mixed $value): mixed
    {
        return match ($this) {
            self::YesNo            => (bool) $value,
            self::Slider           => (float) $value,
            self::MultiOptionCards => is_array($value) ? $value : json_decode((string) $value, true),
            default                => (string) $value,
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::OptionCards, self::MultiOptionCards, self::IconCards, self::Slider]);
    }
}
