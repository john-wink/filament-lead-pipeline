<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Observers;

use JohnWink\FilamentLeadPipeline\Events\LeadBoardStructureChanged;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use RuntimeException;

class LeadFieldDefinitionObserver
{
    public function updating(LeadFieldDefinition $definition): void
    {
        if ( ! $definition->isDirty('key') && ! $definition->isDirty('type')) {
            return;
        }

        if ($definition->is_system || $definition->values()->exists()) {
            throw new RuntimeException(__('lead-pipeline::lead-pipeline.board_edit.field_locked'));
        }
    }

    public function created(LeadFieldDefinition $definition): void
    {
        $this->audit($definition, 'field.added');
    }

    public function updated(LeadFieldDefinition $definition): void
    {
        $this->audit($definition, 'field.updated');
    }

    public function deleted(LeadFieldDefinition $definition): void
    {
        $this->audit($definition, 'field.removed');
    }

    public function deleting(LeadFieldDefinition $definition): void
    {
        if ($definition->isForceDeleting()) {
            return;
        }

        if ($definition->is_system || $definition->values()->exists()) {
            throw new RuntimeException(__('lead-pipeline::lead-pipeline.board_edit.field_delete_blocked'));
        }
    }

    private function audit(LeadFieldDefinition $definition, string $change): void
    {
        if ( ! auth()->check() || ! $definition->board) {
            return;
        }

        LeadBoardStructureChanged::dispatch(
            $definition->board,
            $change,
            ['key' => $definition->key, 'type' => $definition->type?->value],
            auth()->user(),
        );
    }
}
