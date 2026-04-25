// SortableJS wird als IIFE-Bundle geladen (kein Import nötig)
// Filament registriert dieses Script automatisch via FilamentAsset
//
// IIFE + Idempotenz-Guard: Filament/Livewire-SPA-Navigation kann das Script
// erneut auswerten lassen. Ohne Wrapper würde `function initSortable` ein
// zweites Mal im globalen Scope deklariert werden — und ESM/strict-Mode
// wirft "Identifier 's' has already been declared", was das gesamte JS auf
// der Seite stoppt (kein Drag&Drop, keine Live-Filter, kein wire:click).

(function () {
    if (window.__leadPipelineSortableInitialized) {
        return;
    }
    window.__leadPipelineSortableInitialized = true;

    document.addEventListener('livewire:init', () => {
        // Nach jedem Livewire DOM-Update Sortable neu initialisieren
        Livewire.hook('morph.updated', ({ el }) => {
            requestAnimationFrame(() => initSortable(el));
        });

        // Initiales Setup
        requestAnimationFrame(() => initSortable(document));
    });

    function initSortable(container) {
        if (!container || !container.querySelectorAll) return;

        container.querySelectorAll('[data-sortable-phase]').forEach((column) => {
            if (column._sortable) return;

            column._sortable = new Sortable(column, {
                group: 'leads',
                animation: 200,
                easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                handle: '[data-drag-handle]',
                delay: 50,
                delayOnTouchOnly: true,
                touchStartThreshold: 3,
                fallbackTolerance: 3,

                onEnd(evt) {
                    const leadId = evt.item.dataset.leadId;
                    const toPhaseId = evt.to.dataset.sortablePhase;
                    const newSort = evt.newIndex;

                    if (!leadId || !toPhaseId) return;

                    // The kanban board is now rendered directly inside the Filament
                    // Page component (no more inner Livewire wrapper). The page
                    // exposes its component id via `data-kanban-component-id` so we
                    // can dispatch directly to the right Livewire instance.
                    const boardElement = document.querySelector('[data-kanban-board]');
                    if (!boardElement) return;

                    const componentId = boardElement.getAttribute('data-kanban-component-id');
                    if (!componentId) return;

                    const pageComponent = Livewire.find(componentId);

                    if (pageComponent) {
                        pageComponent.call('moveLeadToPhase', leadId, toPhaseId, newSort);
                    }
                },
            });
        });
    }
})();
