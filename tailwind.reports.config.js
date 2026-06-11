/**
 * Eigenständiger Build für die öffentliche Report-Seite (resources/views/reports/**):
 * Die Seite läuft AUSSERHALB der Filament-Panels, bekommt also kein Filament-CSS —
 * deshalb volles Tailwind ohne filament-purge (siehe layout.blade.php).
 */
export default {
    content: [
        './resources/views/reports/**/*.blade.php',
        './src/Filament/Resources/LeadReportResource.php',
    ],
}
