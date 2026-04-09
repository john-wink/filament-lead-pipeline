// SortableJS wird als IIFE-Bundle geladen (kein Import nötig)
// Filament registriert dieses Script automatisch via FilamentAsset

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

                const boardElement = document.querySelector('[data-kanban-board]');
                if (!boardElement) return;

                const wireId = boardElement.getAttribute('wire:id');
                const boardComponent = Livewire.find(wireId);

                if (boardComponent) {
                    boardComponent.call('moveLeadToPhase', leadId, toPhaseId, newSort);
                }
            },
        });
    });
}
