<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Concerns;

use Illuminate\Support\Str;

trait HasConfigurablePrimaryKey
{
    public static function bootHasConfigurablePrimaryKey(): void
    {
        if ('uuid' === config('lead-pipeline.primary_key_type')) {
            static::creating(function ($model): void {
                $keyName = $model->getKeyName();
                $model->{$keyName} ??= Str::uuid7()->toString();
            });
        }
    }

    public static function pkColumn(): string
    {
        return 'uuid' === config('lead-pipeline.primary_key_type') ? 'uuid' : 'id';
    }

    public static function fkColumn(string $relation): string
    {
        $base = Str::snake($relation);

        return 'uuid' === config('lead-pipeline.primary_key_type')
            ? "{$base}_uuid"
            : "{$base}_id";
    }

    public function getIncrementing(): bool
    {
        return 'id' === config('lead-pipeline.primary_key_type');
    }

    public function getKeyType(): string
    {
        return 'uuid' === config('lead-pipeline.primary_key_type') ? 'string' : 'int';
    }

    public function getKeyName(): string
    {
        return 'uuid' === config('lead-pipeline.primary_key_type') ? 'uuid' : 'id';
    }
}
