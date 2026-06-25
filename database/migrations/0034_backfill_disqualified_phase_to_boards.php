<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

return new class() extends Migration {
    /**
     * Stellt sicher, dass jedes Board eine terminale „Nicht qualifiziert"-Phase besitzt.
     * Idempotent: Boards mit bestehender disqualified-Phase bleiben unberührt.
     * Nutzt die Eloquent-Modelle, damit der konfigurierbare Primärschlüssel (uuid/id)
     * korrekt aufgelöst wird.
     */
    public function up(): void
    {
        LeadBoard::query()->withTrashed()->each(function (LeadBoard $board): void {
            $hasDisqualified = $board->phases()
                ->where('type', LeadPhaseTypeEnum::Disqualified->value)
                ->exists();

            if ($hasDisqualified) {
                return;
            }

            $maxSort = (int) $board->phases()->max('sort');

            $board->phases()->create([
                'name'         => 'Nicht qualifiziert',
                'type'         => LeadPhaseTypeEnum::Disqualified->value,
                'display_type' => LeadPhaseDisplayTypeEnum::List->value,
                'color'        => '#F59E0B',
                'sort'         => $maxSort + 1,
            ]);
        });
    }

    public function down(): void
    {
        LeadBoard::query()->withTrashed()->each(function (LeadBoard $board): void {
            $board->phases()
                ->where('type', LeadPhaseTypeEnum::Disqualified->value)
                ->get()
                ->each(fn ($phase) => $phase->forceDelete());
        });
    }
};
