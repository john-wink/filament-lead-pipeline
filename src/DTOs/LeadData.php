<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class LeadData extends Data
{
    public function __construct(
        #[Required]
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        /** @var array<string, mixed> */
        public array $custom_fields = [],
        public ?string $source_driver = null,
        public ?string $source_identifier = null,
        public ?float $value = null,
    ) {}

    /** @return array<string, array<string>> */
    public static function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without:email'],
        ];
    }
}
