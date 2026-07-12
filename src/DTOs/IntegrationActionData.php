<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Data;

class IntegrationActionData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $color = 'gray',
        public bool $requiresConfirmation = false,
        public ?string $confirmText = null,
    ) {}

    /**
     * Literale Tailwind-Klassen je Farbe — dynamische Klassennamen würden
     * vom Tailwind-Build nicht erfasst; Paletten identisch mit den
     * bestehenden Modal-Action-Buttons (won/lost/disqualified/transfer).
     */
    public function buttonClasses(): string
    {
        return match ($this->color) {
            'primary', 'info' => 'inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-white px-3 py-2 text-xs font-medium text-blue-600 hover:bg-blue-50 dark:border-blue-700 dark:bg-gray-800 dark:text-blue-400 dark:hover:bg-blue-900/20 transition-colors',
            'success'         => 'inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-white px-3 py-2 text-xs font-medium text-green-600 hover:bg-green-50 dark:border-green-700 dark:bg-gray-800 dark:text-green-400 dark:hover:bg-green-900/20 transition-colors',
            'danger'          => 'inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-900/20 transition-colors',
            'warning'         => 'inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-medium text-amber-600 hover:bg-amber-50 dark:border-amber-700 dark:bg-gray-800 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors',
            default           => 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 transition-colors',
        };
    }
}
