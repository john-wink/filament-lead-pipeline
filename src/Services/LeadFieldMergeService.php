<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\DTOs\LeadFieldMergeResult;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadBoardStructureChanged;
use JohnWink\FilamentLeadPipeline\Exceptions\InvalidFieldMergeException;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldValue;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Support\MetaCoreFieldDefaults;

class LeadFieldMergeService
{
    /** @var array<string, int> Spaltenlimits der Standard-Felder auf leads */
    private const array SYSTEM_COLUMN_LIMITS = [
        'name'  => 255,
        'email' => 255,
        'phone' => 50,
    ];

    /**
     * Fuehrt zwei Felddefinitionen desselben Boards zusammen: Werte der Quelle
     * wandern zum Ziel (optional durch $valueMap uebersetzt), das Ziel behaelt
     * bei Konflikten seinen Wert — der verworfene Quellwert wird als Activity
     * am Lead dokumentiert. Ist das Ziel ein Standard-Feld (name/email/phone),
     * wandern die Werte in die entsprechende Lead-Spalte. Source-Mappings
     * (z. B. Facebook custom_field_mapping) werden auf das Ziel umgebogen.
     * Die Quelle wird anschliessend soft-geloescht.
     *
     * @param  array<string, string>  $valueMap  alter Quellwert => neuer Zielwert
     */
    public function merge(
        LeadFieldDefinition $source,
        LeadFieldDefinition $target,
        array $valueMap = [],
        ?Model $causer = null,
    ): LeadFieldMergeResult {
        $this->assertMergeable($source, $target);

        return DB::transaction(function () use ($source, $target, $valueMap, $causer): LeadFieldMergeResult {
            $result = $target->is_system
                ? $this->mergeIntoSystemColumn($source, $target, $valueMap, $causer)
                : $this->mergeIntoCustomField($source, $target, $valueMap, $causer);

            $sourcesUpdated = $this->redirectSourceMappings($source, $target);

            $source->delete();

            LeadBoardStructureChanged::dispatch(
                $source->board,
                'field.merged',
                [
                    'source_key'      => $source->key,
                    'target_key'      => $target->key,
                    'moved'           => $result->moved,
                    'deduplicated'    => $result->deduplicated,
                    'conflicts'       => $result->conflicts,
                    'sources_updated' => $sourcesUpdated,
                ],
                $causer,
            );

            return new LeadFieldMergeResult(
                $result->moved,
                $result->deduplicated,
                $result->conflicts,
                $sourcesUpdated,
            );
        });
    }

    private function mergeIntoCustomField(
        LeadFieldDefinition $source,
        LeadFieldDefinition $target,
        array $valueMap,
        ?Model $causer,
    ): LeadFieldMergeResult {
        $moved        = 0;
        $deduplicated = 0;
        $conflicts    = 0;

        $fkLead       = LeadFieldValue::fkColumn('lead');
        $fkDefinition = LeadFieldValue::fkColumn('lead_field_definition');

        $targetValuesByLead = LeadFieldValue::query()
            ->where($fkDefinition, $target->getKey())
            ->get()
            ->keyBy($fkLead);

        foreach ($this->sourceValues($source) as $sourceValue) {
            $newValue = $valueMap[$sourceValue->value] ?? $sourceValue->value;
            $existing = $targetValuesByLead->get($sourceValue->{$fkLead});

            if (null === $existing) {
                $sourceValue->update([
                    $fkDefinition => $target->getKey(),
                    'value'       => $newValue,
                ]);
                $moved++;

                continue;
            }

            if ($existing->value === $newValue) {
                $sourceValue->delete();
                $deduplicated++;

                continue;
            }

            $this->documentDroppedValue($sourceValue, $source, $target, $newValue, $causer);
            $sourceValue->delete();
            $conflicts++;
        }

        return new LeadFieldMergeResult($moved, $deduplicated, $conflicts);
    }

    private function mergeIntoSystemColumn(
        LeadFieldDefinition $source,
        LeadFieldDefinition $target,
        array $valueMap,
        ?Model $causer,
    ): LeadFieldMergeResult {
        $moved        = 0;
        $deduplicated = 0;
        $conflicts    = 0;

        $column = $target->key;
        $limit  = self::SYSTEM_COLUMN_LIMITS[$column] ?? 255;

        foreach ($this->sourceValues($source) as $sourceValue) {
            $lead = $sourceValue->lead;

            if (null === $lead) {
                $sourceValue->delete();

                continue;
            }

            $newValue = $valueMap[$sourceValue->value] ?? $sourceValue->value;
            $current  = (string) ($lead->{$column} ?? '');

            if ('' === $current) {
                $lead->update([$column => mb_substr((string) $newValue, 0, $limit)]);
                $moved++;
            } elseif ($current === (string) $newValue) {
                $deduplicated++;
            } else {
                $this->documentDroppedValue($sourceValue, $source, $target, $newValue, $causer);
                $conflicts++;
            }

            $sourceValue->delete();
        }

        return new LeadFieldMergeResult($moved, $deduplicated, $conflicts);
    }

    /**
     * Biegt Feld-Mappings aller Quellen des Boards auf das Merge-Ziel um.
     * Custom-Ziel: custom_field_mapping zeigt auf den neuen Key. System-Ziel:
     * der Facebook-Key wandert ins field_mapping des Standard-Felds (inklusive
     * der Auto-Erkennungs-Defaults, damit diese nicht verloren gehen).
     */
    private function redirectSourceMappings(LeadFieldDefinition $source, LeadFieldDefinition $target): int
    {
        $boardFk = LeadBoard::fkColumn('lead_board');
        $updated = 0;

        LeadSource::query()
            ->where($boardFk, $source->{$boardFk})
            ->each(function (LeadSource $leadSource) use ($source, $target, &$updated): void {
                $config        = $leadSource->config ?? [];
                $customMapping = $config['custom_field_mapping'] ?? [];
                $changed       = false;

                foreach ($customMapping as $fbKey => $boardKey) {
                    if ($boardKey !== $source->key) {
                        continue;
                    }

                    if ($target->is_system) {
                        unset($customMapping[$fbKey]);

                        $coreList = $config['field_mapping'][$target->key]
                            ?? MetaCoreFieldDefaults::for($target->key);

                        $config['field_mapping'][$target->key] = array_values(array_unique([
                            ...$coreList,
                            $fbKey,
                        ]));
                    } else {
                        $customMapping[$fbKey] = $target->key;
                    }

                    $changed = true;
                }

                if ($changed) {
                    $config['custom_field_mapping'] = $customMapping;
                    $leadSource->update(['config' => $config]);
                    $updated++;
                }
            });

        return $updated;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, LeadFieldValue> */
    private function sourceValues(LeadFieldDefinition $source)
    {
        return LeadFieldValue::query()
            ->where(LeadFieldValue::fkColumn('lead_field_definition'), $source->getKey())
            ->with('lead')
            ->get();
    }

    private function documentDroppedValue(
        LeadFieldValue $sourceValue,
        LeadFieldDefinition $source,
        LeadFieldDefinition $target,
        mixed $newValue,
        ?Model $causer,
    ): void {
        $sourceValue->lead?->activities()->create([
            'type'        => LeadActivityTypeEnum::Updated->value,
            'description' => __('lead-pipeline::lead-pipeline.board_edit.merge_conflict_activity', [
                'source' => $source->name,
                'target' => $target->name,
                'value'  => $newValue,
            ]),
            'properties' => [
                'merged_from'   => $source->key,
                'merged_into'   => $target->key,
                'dropped_value' => $newValue,
            ],
            'causer_type' => $causer?->getMorphClass(),
            'causer_id'   => $causer?->getKey(),
        ]);
    }

    private function assertMergeable(LeadFieldDefinition $source, LeadFieldDefinition $target): void
    {
        if ($source->is($target)) {
            throw new InvalidFieldMergeException('Source and target definition must differ.');
        }

        $boardFk = LeadBoard::fkColumn('lead_board');

        if ($source->{$boardFk} !== $target->{$boardFk}) {
            throw new InvalidFieldMergeException('Definitions must belong to the same board.');
        }

        if ($source->is_system) {
            throw new InvalidFieldMergeException('System fields cannot be merged into another field.');
        }

        if ($target->is_system && ! array_key_exists($target->key, self::SYSTEM_COLUMN_LIMITS)) {
            throw new InvalidFieldMergeException('Unsupported system field as merge target.');
        }

        if ($source->trashed() || $target->trashed()) {
            throw new InvalidFieldMergeException('Trashed definitions cannot be merged.');
        }
    }
}
