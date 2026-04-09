# Filament Lead Pipeline

[![Filament v3](https://img.shields.io/badge/Filament-v3-orange)](https://filamentphp.com)
[![Laravel v12](https://img.shields.io/badge/Laravel-v12-red)](https://laravel.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue)](https://php.net)

A PipeDrive-style lead management system for [Filament 3](https://filamentphp.com). Kanban board, lead funnels, webhook ingestion, Facebook Lead Ads integration, analytics dashboard, and lead conversion — all as a single Filament plugin.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Plugin Registration](#plugin-registration)
- [Concepts](#concepts)
  - [Boards](#boards)
  - [Phases](#phases)
  - [Custom Fields](#custom-fields)
  - [Sources & Drivers](#sources--drivers)
  - [Funnels](#funnels)
  - [Visibility & Permissions](#visibility--permissions)
- [Facebook / Meta Lead Ads](#facebook--meta-lead-ads)
- [Analytics & Reporting](#analytics--reporting)
- [Webhooks & Custom Drivers](#webhooks--custom-drivers)
- [Lead Conversion](#lead-conversion)
- [Events](#events)
- [Internationalization](#internationalization)
- [Configuration](#configuration)
- [Artisan Commands](#artisan-commands)
- [Models & Relationships](#models--relationships)
- [Testing](#testing)
- [License](#license)

---

## Features

- **Kanban Board** — Drag-and-drop leads across phases, async column loading, infinite scroll
- **List Views** — Filament-powered tables for terminal phases (Won, Lost, etc.)
- **Lead Detail Slideover** — Edit fields, assign advisors, add notes, change phases
- **Lead Sources** — API, Zapier, Facebook/Meta, Funnel, and Manual with pluggable driver architecture
- **Facebook Lead Ads** — Native OAuth integration, field mapping, historical import
- **Lead Funnels** — Public multi-step forms with customizable design
- **Analytics Dashboard** — KPIs, trend charts, advisor matrix, source performance, CSV export
- **Lead Conversion** — Convert leads to any Eloquent model with custom forms
- **Role-Based Visibility** — Board admins see everything, advisors see only their assigned leads
- **Auto-Move on Assignment** — Leads automatically advance from Open to InProgress when assigned
- **Raw Data Storage** — Full webhook payloads stored for debugging and UTM tracking
- **Multi-Tenancy** — Full team/tenant isolation out of the box
- **Internationalization** — Complete translations in English, German, and French
- **Configurable Primary Keys** — UUID or auto-increment, your choice

---

## Requirements

- PHP 8.2+
- Laravel 12+
- Filament 3.x
- Spatie Laravel Data 4.x

---

## Installation

```bash
composer require john-wink/filament-lead-pipeline
```

Publish config and run migrations:

```bash
php artisan vendor:publish --tag="lead-pipeline-config"
php artisan vendor:publish --tag="lead-pipeline-migrations"
php artisan migrate
```

### Prepare your Team Model

Add the `HasLeadBoards` trait to your team/tenant model:

```php
use JohnWink\FilamentLeadPipeline\Concerns\HasLeadBoards;

class Team extends Model
{
    use HasLeadBoards;
}
```

This provides the `leadBoards()` relationship required by Filament's multi-tenancy.

---

## Plugin Registration

Register the plugin in your `AdminPanelServiceProvider`:

```php
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\DTOs\PhasePresetData;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;

$panel->plugin(
    FilamentLeadPipelinePlugin::make()
        ->converters([
            'customer' => CustomerLeadConverter::class,
        ])
        ->defaultPhases([
            new PhasePresetData(name: 'New', type: LeadPhaseTypeEnum::Open, color: '#6B7280'),
            new PhasePresetData(name: 'Contacted', type: LeadPhaseTypeEnum::InProgress, color: '#3B82F6'),
            new PhasePresetData(name: 'Qualified', type: LeadPhaseTypeEnum::InProgress, color: '#8B5CF6'),
            new PhasePresetData(name: 'Won', type: LeadPhaseTypeEnum::Won, display_type: LeadPhaseDisplayTypeEnum::List, color: '#10B981'),
            new PhasePresetData(name: 'Lost', type: LeadPhaseTypeEnum::Lost, display_type: LeadPhaseDisplayTypeEnum::List, color: '#EF4444'),
        ])
        ->assignableUsersQuery(fn ($query) => $query->whereRelation('teams', 'uuid', tenant()->getKey()))
);
```

### Plugin Methods

| Method | Description |
|--------|-------------|
| `converters(array $converters)` | Register lead converters (key → class) |
| `defaultPhases(array $phases)` | Default phases for new boards |
| `defaultFields(array $fields)` | Default custom fields for new boards |
| `defaultSources(array $sources)` | Default sources for new boards |
| `sourceManagement(bool $enabled)` | Toggle source management UI |
| `funnelBuilder(bool $enabled)` | Toggle funnel builder UI |
| `assignableUsersQuery(callable $callback)` | Filter which users appear in assignment dropdowns |

---

## Concepts

### Boards

A board is the central entity. It defines phases, custom fields, and contains leads. Boards are team-scoped (multi-tenancy). Each board has its own set of admins who can see all leads and assign them.

### Phases

Phases have two dimensions:

**Type** (`LeadPhaseTypeEnum`):
- `Open` — New, unprocessed leads
- `InProgress` — Being worked on
- `Won` — Successfully closed
- `Lost` — Deal lost

**Display** (`LeadPhaseDisplayTypeEnum`):
- `Kanban` — Column on the kanban board (drag-and-drop)
- `List` — Tab with a Filament table (sortable, searchable, paginated)

Leads can be moved between kanban columns via drag-and-drop, and to list phases via card actions.

### Custom Fields

Fields are defined per board (`LeadFieldDefinition`). Supported types:

| Type | Description |
|------|-------------|
| `String` | Text (max 255) |
| `Email` | Email address |
| `Phone` | Phone number |
| `Number` | Integer |
| `Decimal` | Decimal number |
| `Currency` | Currency amount |
| `Boolean` | Yes/No |
| `Date` | Date |
| `Select` | Single select |
| `MultiSelect` | Multi select |
| `Textarea` | Multi-line text |
| `Url` | URL/Link |

Values are stored as `LeadFieldValue` (EAV pattern):

```php
$lead->setFieldValue($fieldDefinition, 'some value');
$value = $lead->getFieldValue('field_key');
```

### Sources & Drivers

Sources define where leads come from. Each source is bound to a board.

| Driver | Description |
|--------|-------------|
| `Api` | Generic REST webhook with bearer token auth |
| `Zapier` | Zapier integration with configurable field mapping |
| `Meta` | Facebook/Meta Lead Ads with OAuth and auto field mapping |
| `Funnel` | Public lead capture funnel (multi-step form) |
| `Manual` | Manually created leads |

Each driver controls its own config form, webhook URL generation, signature verification, and table actions.

### Funnels

Funnels are public multi-step forms for lead capture. They are linked to a source of type `funnel`.

**Funnel field types** (independent of board field types):

| Type | Description |
|------|-------------|
| `TextInput` | Standard text field |
| `EmailInput` | Email with validation |
| `PhoneInput` | Phone input |
| `Textarea` | Multi-line text |
| `OptionCards` | Large clickable cards (single select) |
| `MultiOptionCards` | Clickable cards (multi select) |
| `YesNo` | Two large Yes/No buttons |
| `Slider` | Range slider with value display |
| `IconCards` | Cards with icon + label |
| `DatePicker` | Date picker |

Funnel URLs: `GET /{route_prefix}/{slug}` (public, no auth required)

### Visibility & Permissions

- **Board Admins** — See all leads, can assign advisors, access analytics for the board
- **Advisors** (non-admins) — See only leads assigned to them
- **Unassigned leads** in Open phases — Visible only to admins

When a lead is assigned from an Open phase, it automatically moves to the first InProgress phase.

---

## Facebook / Meta Lead Ads

Native integration with Facebook Lead Ads. Leads are received via webhooks in production, or imported manually via the Graph API.

### Setup

1. Create a **Facebook App** at [developers.facebook.com](https://developers.facebook.com)
2. Enable the **Facebook Login** product
3. Set **environment variables**:

```env
FACEBOOK_CLIENT_ID=your-app-id
FACEBOOK_CLIENT_SECRET=your-app-secret
FACEBOOK_CLIENT_TOKEN=your-client-token
FACEBOOK_REDIRECT_URI=https://your-domain.com/lead-pipeline/facebook/callback
FACEBOOK_VERIFY_TOKEN=a-random-string-for-webhook-verification
```

> **Note:** `FACEBOOK_APP_ID` and `FACEBOOK_APP_SECRET` are accepted as fallbacks for `FACEBOOK_CLIENT_ID` and `FACEBOOK_CLIENT_SECRET` respectively.

4. **Connect your account** — Click "Connect with Facebook" in the source form (opens OAuth popup)
5. **Or via Artisan:**

```bash
php artisan lead-pipeline:connect-facebook --user=<uuid> --team=<uuid>
```

### Production Webhook

In the Facebook Developer Console under **Webhooks > Page**:
- **Callback URL:** `https://your-domain.com/api/lead-pipeline/webhooks/meta`
- **Verify Token:** Your `FACEBOOK_VERIFY_TOKEN` value
- **Field:** `leadgen`

### Field Mapping

When creating a Meta source:
1. Select a page and lead forms
2. Click **"Load Fields"** — fetches all form field definitions from Facebook
3. Standard fields (name, email, phone) are auto-mapped
4. Map custom fields manually, or click **"Auto-create missing fields"** to generate board fields

### Historical Import

Use the **"Import Leads"** action on a Meta source. Select a time range (30–365 days). Duplicates are detected by Facebook Lead ID and email.

---

## Analytics & Reporting

Fullscreen slideover with KPIs, charts, and tables — fully async (no data loaded until opened).

### Entry Points
- **In a board:** "Analytics" button in the toolbar — shows board-specific data
- **In the board list:** "Analytics" header action — shows team-wide data (only boards where user is admin)

### Sections
1. **KPI Cards** — Total leads, new, won, lost, conversion rate, avg. value
2. **Trend Chart** — Leads per day/week (line chart)
3. **Advisor × Phase Matrix** — Table + stacked bar chart
4. **Source Performance** — Table + donut chart

### Time Range
Quick presets (Today, 7/30/90 days, All) + custom from/to date range.

### CSV Export
Dropdown with 4 export options:
- **Complete** — All sections in one file
- **Advisor Overview** — All phases + KPIs per advisor
- **Advisor × Phases** — Matrix table
- **Source Performance** — Source table

### Permissions
- **Board admins** see all data
- **Non-admins** see only their own assigned leads
- **Team overview** only includes boards where the user is admin

---

## Webhooks & Custom Drivers

Webhook endpoints are registered automatically:

```
POST /api/lead-pipeline/webhooks/{sourceId}        # Generic (API, Zapier)
POST /api/lead-pipeline/webhooks/meta               # Centralized Meta endpoint
GET  /api/lead-pipeline/webhooks/meta               # Meta verification
```

All webhook routes are rate-limited (configurable, default: 60/min).

### Creating a Custom Driver

Implement `LeadSourceDriver`:

```php
use JohnWink\FilamentLeadPipeline\Contracts\LeadSourceDriver;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class MyDriver implements LeadSourceDriver
{
    public function getDisplayName(): string { return 'My Service'; }

    public function validateConfig(array $config): bool { return true; }

    public function processWebhook(WebhookPayloadData $payload, LeadSource $source): LeadData
    {
        $data = $payload->raw_payload;

        return new LeadData(
            name: $data['name'] ?? '',
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            custom_fields: $data,
            source_driver: 'my_service',
            source_identifier: (string) $source->getKey(),
        );
    }

    public function verifySignature(string $payload, string $signature, LeadSource $source): bool
    {
        return hash_equals($source->api_token ?? '', $signature);
    }

    public function getConfigFormSchema(): array { return []; }

    public function getWebhookUrl(LeadSource $source): string
    {
        return FilamentLeadPipelinePlugin::publicUrl(
            config('lead-pipeline.webhooks.prefix') . '/' . $source->getKey()
        );
    }

    public function getDefaultFieldMapping(): array
    {
        return ['name' => 'name', 'email' => 'email', 'phone' => 'phone'];
    }

    public function getTableActions(LeadSource $source): array { return []; }
}
```

Register in config:

```php
'drivers' => [
    'my_service' => \App\LeadDrivers\MyDriver::class,
],
```

---

## Lead Conversion

Leads can be converted into any Eloquent model via pluggable converters.

### Creating a Converter

```php
use JohnWink\FilamentLeadPipeline\Contracts\LeadConverter;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use Illuminate\Database\Eloquent\Model;

class CustomerConverter implements LeadConverter
{
    public function getDisplayName(): string { return 'Create Customer'; }

    public function getTargetModelClass(): string { return \App\Models\Customer::class; }

    public function getConversionFormSchema(Lead $lead): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('company')->label('Company')->required(),
        ];
    }

    public function convert(Lead $lead, array $additionalData = []): Model
    {
        return \App\Models\Customer::create([
            'name'    => $lead->name,
            'email'   => $lead->email,
            'phone'   => $lead->phone,
            'company' => $additionalData['company'] ?? null,
        ]);
    }

    public function validate(Lead $lead): array
    {
        return $lead->email ? [] : ['Email is required to create a customer.'];
    }
}
```

Register converters:

```php
FilamentLeadPipelinePlugin::make()
    ->converters(['customer' => CustomerConverter::class])
```

### `ConvertsLeads` Trait

Add to models that can be created from leads:

```php
use JohnWink\FilamentLeadPipeline\Traits\ConvertsLeads;

class Customer extends Model
{
    use ConvertsLeads;
}

$customer->wasConvertedFromLead(); // bool
$customer->getSourceLead();        // ?Lead
```

---

## Events

| Event | Properties | Fired when |
|-------|------------|------------|
| `LeadCreated` | `Lead $lead` | Lead created (webhook, funnel, manual) |
| `LeadAssigned` | `Lead $lead`, `?Model $assignedUser`, `?Model $assignedBy` | Lead assigned to an advisor |
| `LeadMoved` | `Lead $lead`, `LeadPhase $fromPhase`, `LeadPhase $toPhase` | Lead moved between phases |
| `LeadStatusChanged` | `Lead $lead`, `LeadStatusEnum $oldStatus`, `LeadStatusEnum $newStatus` | Status changed (Won/Lost) |
| `LeadConverted` | `Lead $lead`, `Model $convertedModel` | Lead converted to another model |
| `LeadReceived` | `LeadSource $source`, `array $payload` | Webhook received (before processing) |

```php
// Example listener
class SendWelcomeEmail
{
    public function handle(LeadCreated $event): void
    {
        Mail::to($event->lead->email)->send(new WelcomeMail());
    }
}
```

Register listeners in your `EventServiceProvider`:

```php
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;

protected $listen = [
    LeadCreated::class => [SendWelcomeEmail::class],
];
```

---

## Internationalization

The package ships with complete translations in **English**, **German**, and **French**.

The active language follows `config('app.locale')` automatically. To customize translations, publish them:

```bash
php artisan vendor:publish --tag="lead-pipeline-translations"
```

Then edit files in `lang/vendor/lead-pipeline/{locale}/`.

---

## Configuration

```bash
php artisan vendor:publish --tag="lead-pipeline-config"
```

```php
// config/lead-pipeline.php

return [
    // Primary key type: 'uuid' or 'id'
    'primary_key_type' => 'uuid',

    // Public URL for external-facing routes (funnels, webhooks, OAuth).
    // Set this when using a custom domain/subdomain (e.g., for Vapor/Lambda).
    // Falls back to config('app.url') when null.
    'public_url' => env('LEAD_PIPELINE_PUBLIC_URL'),

    // Multi-tenancy
    'tenancy' => [
        'enabled'     => true,
        'model'       => \App\Models\Team::class,
        'foreign_key' => 'team_uuid',
    ],

    // User model
    'user_model'       => \App\Models\User::class,
    'user_foreign_key' => 'user_uuid',

    // Kanban settings
    'kanban' => [
        'leads_per_page'        => 20,
        'card_fields_limit'     => 5,
        'auto_refresh_interval' => 30, // seconds, 0 = disabled
    ],

    // Registered webhook drivers
    'drivers' => [
        'zapier' => \JohnWink\FilamentLeadPipeline\Drivers\ZapierDriver::class,
        'meta'   => \JohnWink\FilamentLeadPipeline\Drivers\MetaDriver::class,
        'api'    => \JohnWink\FilamentLeadPipeline\Drivers\ApiDriver::class,
        'funnel' => \JohnWink\FilamentLeadPipeline\Drivers\FunnelDriver::class,
    ],

    // Webhook configuration
    'webhooks' => [
        'prefix'     => 'api/lead-pipeline/webhooks',
        'middleware'  => ['api'],
        'rate_limit'  => 60, // requests per minute
    ],

    // Funnel configuration
    'funnel' => [
        'route_prefix' => 'funnel',
        'middleware'    => ['web'],
    ],

    // Facebook / Meta integration
    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'client_token'  => env('FACEBOOK_CLIENT_TOKEN'),
        'redirect_uri'  => env('FACEBOOK_REDIRECT_URI'),
        'verify_token'  => env('FACEBOOK_VERIFY_TOKEN'),
        'graph_version' => 'v21.0',
        'scopes'        => ['pages_manage_ads', 'leads_retrieval', 'pages_show_list'],
    ],

    // Conversion settings
    'conversion' => [
        'auto_convert_on_won'          => false,
        'delete_lead_after_conversion' => false,
    ],

    // Navigation
    'navigation' => [
        'group' => null,
        'icon'  => 'heroicon-o-funnel',
        'sort'  => 10,
        'label' => 'Leads',
    ],
];
```

---

## Artisan Commands

### Generate Demo Data

```bash
php artisan lead-pipeline:demo-data --board=<uuid>
php artisan lead-pipeline:demo-data --board=<uuid> --count=500 --with-activities
php artisan lead-pipeline:demo-data --board=<uuid> --count=200 --phase-distribution=equal
```

| Flag | Description | Default |
|------|-------------|---------|
| `--board=` | Board UUID/ID | required |
| `--count=` | Number of leads | 50 |
| `--with-activities` | Generate activity entries | false |
| `--phase-distribution=` | `equal` or `realistic` | `realistic` |

### Connect Facebook

```bash
# Interactive (prompts for token)
php artisan lead-pipeline:connect-facebook --user=<uuid> --team=<uuid>

# With token from Graph API Explorer
php artisan lead-pipeline:connect-facebook --user=<uuid> --team=<uuid> --token=<access-token>
```

Connects a Facebook account, exchanges for a long-lived token, loads pages and forms, and subscribes to leadgen webhooks.

---

## Models & Relationships

```
LeadBoard (team-scoped)
 ├── hasMany LeadPhase
 ├── hasMany LeadFieldDefinition
 ├── hasMany Lead
 ├── hasMany LeadSource
 ├── hasMany LeadFunnel
 └── belongsToMany User (admins)

Lead (soft deletes)
 ├── belongsTo LeadBoard
 ├── belongsTo LeadPhase
 ├── belongsTo LeadSource
 ├── belongsTo User (assigned_to)
 ├── hasMany LeadFieldValue
 ├── hasMany LeadActivity
 └── hasMany LeadConversion

LeadSource (team-scoped)
 ├── belongsTo LeadBoard
 ├── belongsTo FacebookPage (optional)
 └── hasOne LeadFunnel

LeadFunnel
 └── hasMany LeadFunnelStep
       └── hasMany LeadFunnelStepField
             └── belongsTo LeadFieldDefinition

FacebookConnection
 ├── belongsTo User
 └── hasMany FacebookPage
       └── hasMany FacebookForm
```

### Primary Keys

The package supports both UUID and auto-increment IDs, configurable via `primary_key_type`. All models use the `HasConfigurablePrimaryKey` trait:

```php
$fk = LeadBoard::fkColumn('lead_board'); // → 'lead_board_uuid' or 'lead_board_id'
$lead->getKey();                          // → UUID string or integer
```

---

## Testing

Tests are included in the package at `tests/Feature/`:

```bash
# Run all lead pipeline tests
php artisan test --parallel --testsuite=LeadPipeline

# Run a specific test file
php artisan test packages/john-wink/filament-lead-pipeline/tests/Feature/KanbanBoardTest.php
```

Factories are available for all models:

```php
$board = LeadBoard::factory()->withDefaultPhases()->create();
$phase = LeadPhase::factory()->for($board, 'board')->open()->create();
$lead  = Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();
```

---

## License

Proprietary. Authorized projects only.
