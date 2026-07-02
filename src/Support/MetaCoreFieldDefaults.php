<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Support;

/**
 * Fallback-Feldnamen fuer die Auto-Erkennung der Core-Felder in
 * Facebook/Meta-Lead-Payloads. Gilt immer dann, wenn eine Quelle kein
 * explizites field_mapping fuer den jeweiligen Key konfiguriert hat.
 */
final class MetaCoreFieldDefaults
{
    /** @var array<string, list<string>> */
    public const array DEFAULTS = [
        'name'       => ['full_name', 'vollständiger_name'],
        'first_name' => ['first_name', 'vorname'],
        'last_name'  => ['last_name', 'nachname'],
        'email'      => ['email', 'e-mail-adresse', 'e-mail'],
        'phone'      => ['phone_number', 'telefonnummer', 'phone'],
    ];

    /** @return list<string> */
    public static function for(string $coreKey): array
    {
        return self::DEFAULTS[$coreKey] ?? [];
    }
}
