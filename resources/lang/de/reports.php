<?php

declare(strict_types=1);

return [
    'password_required'    => 'Dieser Report ist passwortgeschützt',
    'password_placeholder' => 'Passwort eingeben',
    'password_submit'      => 'Report öffnen',
    'password_invalid'     => 'Das Passwort ist nicht korrekt.',
    'synced_at'            => 'Stand: :date',
    'imprint'              => 'Impressum',
    'download_pdf'         => 'PDF herunterladen',
    'creatives_totals'     => 'Gesamt: :impressions Impr. · :leads Leads · :spend €',

    'presets' => [
        'today'      => 'Heute',
        'last7days'  => 'Letzte 7 Tage',
        'last30days' => 'Letzte 30 Tage',
        'last90days' => 'Letzte 90 Tage',
        'this_month' => 'Dieser Monat',
        'last_month' => 'Letzter Monat',
        'all_time'   => 'Gesamter Zeitraum',
        'custom'     => 'Eigener Zeitraum',
    ],

    'sections' => [
        'kpis'      => 'Kennzahlen',
        'trend'     => 'Verlauf',
        'gender'    => 'Geschlecht',
        'funnel'    => 'Vertriebs-Funnel',
        'creatives' => 'Anzeigen',
        'claim'     => 'Über uns',
    ],

    'kpis' => [
        'inquiries'        => 'Anfragen',
        'cost_per_inquiry' => 'Kosten pro Anfrage',
        'spend'            => 'Ausgegebenes Budget',
        'reach'            => 'Reichweite',
        'impressions'      => 'Impressionen',
        'link_clicks'      => 'Link-Klicks',
    ],

    'gender' => [
        'male'    => 'Männer',
        'female'  => 'Frauen',
        'unknown' => 'Unbekannt',
    ],

    'funnel' => [
        'impressions' => 'Impressionen',
        'link_clicks' => 'Link-Klicks',
        'inquiries'   => 'Anfragen',
        'qualified'   => 'Qualifiziert',
        'won'         => 'Abschlüsse',
    ],

    'mail' => [
        'subject'      => 'Ihr aktueller Marketing-Report: :name',
        'intro'        => 'Ihr aktueller Marketing-Report ":name" steht bereit:',
        'failed_title' => 'Report-Versand fehlgeschlagen',
        'failed_body'  => 'Der geplante Versand des Reports ":name" ist mehrfach fehlgeschlagen.',
    ],

    'actions' => [
        'preview'           => 'Vorschau öffnen',
        'refresh'           => 'Jetzt aktualisieren',
        'refresh_started'   => 'Synchronisierung gestartet',
        'refresh_throttled' => 'Synchronisierung läuft bereits — bitte in 15 Minuten erneut versuchen.',
        'rotate_token'      => 'Link erneuern',
        'download_pdf'      => 'PDF erzeugen',
    ],
    'resource' => [
        'singular' => 'Report',
        'plural'   => 'Reports',
        'tabs'     => [
            'content'    => 'Inhalt',
            'branding'   => 'Branding',
            'sharing'    => 'Teilen',
            'scheduling' => 'Versand',
        ],
        'fields' => [
            'date_preset_default' => 'Standard-Zeitraum',
            'date_locked'         => 'Zeitraum für Betrachter sperren',
            'sections'            => 'Sektionen',
            'funnel_mapping'      => 'Funnel-Zuordnung',
            'boards'              => 'Boards (Lead-Daten)',
            'ad_sources'          => 'Meta-Werbekonten',
            'connection'          => 'Facebook-Verbindung',
            'ad_account'          => 'Werbekonto',
            'campaigns'           => 'Kampagnen',
            'campaigns_hint'      => 'Leer = alle Kampagnen des Kontos',
            'missing_ads_read'    => 'Dieser Verbindung fehlt die ads_read-Berechtigung.',
            'reconnect'           => 'Neu verbinden',
            'logo'                => 'Logo',
            'co_logo'             => 'Co-Branding-Logo',
            'accent_color'        => 'Akzentfarbe',
            'claim'               => 'Claim / Intro-Text',
            'footer_text'         => 'Footer-Text',
            'branding_effective'  => 'Effektives Branding',
            'branding_inherited'  => 'Aufgelöste Akzentfarbe: :color (leer = erbt vom Team/Plattform-Default)',
            'share_url'           => 'Share-Link',
            'copy_link'           => 'Link kopieren',
            'password'            => 'Neues Passwort setzen',
            'expires_at'          => 'Gültig bis',
            'is_active'           => 'Aktiv',
            'view_stats'          => 'Aufrufe (30 Tage)',
            'schedules'           => 'Zeitpläne',
            'weekly'              => 'Wöchentlich',
            'monthly'             => 'Monatlich',
            'send_log'            => 'Sendeverlauf (letzte 10)',
            'sync_state'          => 'Sync',
            'stale_hint'          => 'Daten veraltet — letzter erfolgreicher Sync liegt zurück oder ist fehlgeschlagen.',
        ],
        'validation' => [
            'foreign_connection' => 'Diese Facebook-Verbindung gehört nicht zu Ihrem Team.',
            'foreign_board'      => 'Dieses Board ist für Ihr Team nicht freigegeben.',
        ],
    ],
];
