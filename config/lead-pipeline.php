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
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Drivers
    |--------------------------------------------------------------------------
    | Registrierte Quell-Treiber. Neue Treiber hier registrieren.
    */
    'drivers' => [
        'zapier' => JohnWink\FilamentLeadPipeline\Drivers\ZapierDriver::class,
        'meta'   => JohnWink\FilamentLeadPipeline\Drivers\MetaDriver::class,
        'api'    => JohnWink\FilamentLeadPipeline\Drivers\ApiDriver::class,
        'funnel' => JohnWink\FilamentLeadPipeline\Drivers\FunnelDriver::class,
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
        'scopes'        => ['pages_manage_ads', 'leads_retrieval', 'pages_show_list', 'business_management'],
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
    | Navigation
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'group' => null,
        'icon'  => 'heroicon-o-funnel',
        'sort'  => 10,
        'label' => 'Leads',
    ],
];
