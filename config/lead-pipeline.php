<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    | 'uuid' → Uses UUID (uuid7) as primary key (like finance-estate)
    | 'id'   → Uses auto-incrementing integer as primary key
    */
    'primary_key_type' => 'uuid',

    /*
    |--------------------------------------------------------------------------
    | Team/Tenant Configuration
    |--------------------------------------------------------------------------
    | Enable multi-tenancy support. When enabled, boards are scoped to teams.
    |
    | 'model' is also used to resolve the tenant on routes that run outside a Filament
    | panel (e.g. LeadOperationsExportController), where filament()->getTenant() is
    | null because the panel's own tenancy middleware never ran. Those routes require
    | this to be set and abort with 403 if it is not.
    */
    'tenancy' => [
        'enabled'     => true,
        'model'       => App\Models\Team::class,
        'foreign_key' => 'team_uuid', // Angepasst an Primary Key Type
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    */
    'user_model'       => App\Models\User::class,
    'user_foreign_key' => 'user_uuid',

    /*
    |--------------------------------------------------------------------------
    | Kanban Board Settings
    |--------------------------------------------------------------------------
    */
    'kanban' => [
        'leads_per_page'        => 20,        // Lazy Loading: Leads pro Phase-Ladung
        'card_fields_limit'     => 5,       // Max Custom Fields auf der Karte
        'auto_refresh_interval' => 30,  // Sekunden, 0 = deaktiviert
        'stale_warning_days'    => 7,     // Alter-Badge wird gelb (Tage ohne Aktivität)
        'stale_critical_days'   => 30,    // Alter-Badge wird rot (Tage ohne Aktivität)
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations / Mitarbeiter-Auswertung
    |--------------------------------------------------------------------------
    */
    'operations' => [
        'sla_minutes'   => 60,   // Erstreaktions-SLA in Minuten
        'score_weights' => [     // Score v2 Teilscore-Gewichte (werden normalisiert)
            'activity'  => 30,
            'tempo'     => 25,
            'result'    => 30,
            'diligence' => 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Drivers
    |--------------------------------------------------------------------------
    | Registrierte Quell-Treiber. Neue Treiber hier registrieren.
    */
    'drivers' => [
        'zapier'      => JohnWink\FilamentLeadPipeline\Drivers\ZapierDriver::class,
        'meta'        => JohnWink\FilamentLeadPipeline\Drivers\MetaDriver::class,
        'api'         => JohnWink\FilamentLeadPipeline\Drivers\ApiDriver::class,
        'funnel'      => JohnWink\FilamentLeadPipeline\Drivers\FunnelDriver::class,
        'immoscout24' => JohnWink\FilamentLeadPipeline\Drivers\ImmoScoutDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public URL
    |--------------------------------------------------------------------------
    | Base URL for publicly accessible routes (funnels, webhooks, OAuth).
    | Set this when using a custom domain/subdomain for lead pages.
    | If null, falls back to config('app.url').
    |
    | Example: 'https://leads.yourdomain.com'
    */
    'public_url' => env('LEAD_PIPELINE_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'prefix'     => 'api/lead-pipeline/webhooks',
        'middleware' => ['api'],
        'rate_limit' => 60,

        'logging' => [
            'enabled'        => env('LEAD_PIPELINE_WEBHOOK_LOG', true),
            'channel'        => 'lead-webhooks',
            'store_payload'  => true,
            'retention_days' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Funnel Configuration
    |--------------------------------------------------------------------------
    */
    'funnel' => [
        'route_prefix' => 'funnel',
        'middleware'   => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Facebook / Meta Integration
    |--------------------------------------------------------------------------
    */
    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID', env('FACEBOOK_APP_ID')),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET', env('FACEBOOK_APP_SECRET')),
        'client_token'  => env('FACEBOOK_CLIENT_TOKEN'),
        'redirect_uri'  => env('FACEBOOK_REDIRECT_URI'),
        'verify_token'  => env('FACEBOOK_VERIFY_TOKEN'),
        'graph_version' => 'v25.0',
        'scopes'        => ['pages_manage_ads', 'pages_manage_metadata', 'leads_retrieval', 'pages_show_list', 'business_management', 'ads_read'],
        'alerts'        => [
            // Panel-Notifications an Connection-Besitzer bei Token-Problemen
            'enabled' => true,
        ],

        'refresh' => [
            'enabled'      => env('LEAD_PIPELINE_FB_REFRESH_ENABLED', true),
            'cadence'      => env('LEAD_PIPELINE_FB_REFRESH_CADENCE', 'hourly'),
            'queue'        => env('LEAD_PIPELINE_FB_REFRESH_QUEUE', false),
            'warning_days' => 7,
            'max_attempts' => 5,
            'backoff_base' => 300,    // seconds
            'backoff_max'  => 21600,  // 6 hours
            'health_hours' => 3,      // alert if a connected token is >N h past expiry
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta Conversion-Leads Feedback
    |--------------------------------------------------------------------------
    | CRM→Conversions-API-Feedback-Schleife: meldet Lead-Ergebnisse (gewonnen /
    | verloren / nicht qualifiziert) zurück an Meta, damit die Lead-Qualität
    | optimiert wird. Config-gated und queued — leer/disabled = No-Op.
    |
    | Multi-Tenant: Es gibt KEINE globale dataset_id / kein globales Token. Beide
    | werden PRO LEAD aufgelöst — die dataset_id aus dem Promoted-Object des Adsets
    | der Anzeige (über source_ad_id), das Token aus der Quell-FacebookConnection des
    | Leads. So liefert jede Marketing-Firma auf derselben Installation an ihr eigenes
    | Dataset mit ihrem eigenen Token.
    */
    'meta' => [
        'conversions' => [
            'enabled'       => env('LEAD_PIPELINE_META_CONVERSIONS_ENABLED', false),
            'graph_version' => env('LEAD_PIPELINE_META_GRAPH_VERSION', 'v21.0'),
            'cache_ttl'     => (int) env('LEAD_PIPELINE_META_DATASET_CACHE_TTL', 86400),
            'event_map'     => [
                'won'          => 'closed_won',
                'lost'         => 'closed_lost',
                'disqualified' => 'disqualified',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ImmoScout24 Integration
    |--------------------------------------------------------------------------
    | Baufinanzierungs-Leads werden per Polling importiert (die Lead-API ist
    | pull-only, ohne Webhooks). Credentials liegen pro Team in
    | immoscout_connections; hier steht nur der Scheduler-Takt.
    */
    'immoscout' => [
        // App-Level-Credentials (Self-Service-Portal von ImmoScout24). Werden für
        // den "Verbinden"-Button (3-legged OAuth) genutzt und beim Connect in die
        // Team-Connection kopiert. Ohne Key ist nur die manuelle Anlage möglich.
        'consumer_key'    => env('IMMOSCOUT_CONSUMER_KEY'),
        'consumer_secret' => env('IMMOSCOUT_CONSUMER_SECRET'),
        'environment'     => env('IMMOSCOUT_ENVIRONMENT', 'production'),

        'sync' => [
            'enabled' => env('LEAD_PIPELINE_IMMOSCOUT_SYNC_ENABLED', true),
            'cadence' => env('LEAD_PIPELINE_IMMOSCOUT_SYNC_CADENCE', 'everyFifteenMinutes'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wiedervorlage / Reminders
    |--------------------------------------------------------------------------
    | Scheduler benachrichtigt zugewiesene Berater über fällige Wiedervorlagen.
    |
    | 'notification' erlaubt der Host-App, eine eigene Notification-Klasse
    | (z. B. mit Mail-/Push-Kanälen) statt der einfachen Package-Notification
    | einzuhängen. Muss denselben Konstruktor akzeptieren: __construct(Lead $lead).
    */
    'reminders' => [
        'enabled'      => true,
        'notification' => JohnWink\FilamentLeadPipeline\Notifications\LeadReminderDue::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Conversion
    |--------------------------------------------------------------------------
    */
    'conversion' => [
        'auto_convert_on_won'          => false,
        'delete_lead_after_conversion' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Transfer (Board-zu-Board-Übergabe)
    |--------------------------------------------------------------------------
    | enabled: globaler Kill-Switch. Pro Board wird die Übergabe über
    | board.settings['transfer_enabled'] aktiviert (Default aus).
    | board_filter: optionaler Klassen-Resolver (TransferTargetBoardFilter),
    | um die Ziel-Board-Auswahl projektspezifisch einzuschränken.
    */
    'transfer' => [
        'enabled'      => true,
        'board_filter' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'group' => null,
        'icon'  => 'heroicon-o-funnel',
        'sort'  => 10,
        'label' => 'Leads',
    ],
    /*
    |--------------------------------------------------------------------------
    | Marketing Reports
    |--------------------------------------------------------------------------
    */
    'reports' => [
        'route_prefix' => 'reports',
        'middleware'   => ['web'],
        'media_disk'   => env('LEAD_PIPELINE_REPORTS_DISK', 'public'),

        'branding_resolver' => JohnWink\FilamentLeadPipeline\Services\ConfigReportBrandingResolver::class,
        'pdf_renderer'      => JohnWink\FilamentLeadPipeline\Services\NullReportPdfRenderer::class,

        'defaults' => [
            'accent_color' => '#0f766e',
            'logo_url'     => null,
            'footer_text'  => null,
            'contact'      => null,
            'imprint_url'  => null,
        ],

        // Panel-IDs, in denen die Spatie-Permissions erzwungen werden (null = überall);
        // in anderen Panels gilt nur die Team-Isolation
        'permission_panels' => null,

        'permissions' => [
            'view'   => 'view_reports',
            'create' => 'create_reports',
            'update' => 'update_reports',
            'delete' => 'delete_reports',
            'share'  => 'manage_sharing',
        ],

        'sync' => [
            'enabled'            => true,
            'daily_at'           => '04:00',
            'hourly_current_day' => true,
            'backfill_days'      => 28,
        ],

        'scheduling' => [
            'enabled' => true,
        ],
    ],
];
