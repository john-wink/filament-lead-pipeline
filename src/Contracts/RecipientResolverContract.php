<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves polymorphic recipients for LeadBoard routing.
 *
 * Implementations describe which Eloquent model can serve as a board
 * recipient and how to fetch options or look up an instance by id.
 */
interface RecipientResolverContract
{
    /** Human-readable label for this recipient type (used in UI selects). */
    public function label(): string;

    /**
     * Query that produces the list of selectable recipients.
     *
     * The optional $context lets implementations narrow the result based
     * on the calling user, current tenant, or a board being configured.
     */
    public function optionsQuery(?Model $context = null): Builder;

    /** Looks up a single recipient model by its primary key value. */
    public function resolveModel(string $id): ?Model;
}
