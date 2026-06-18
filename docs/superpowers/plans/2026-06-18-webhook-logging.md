# Webhook-Logging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jedes Webhook-Ereignis (eingehender Lead, Facebook-Registrierung, Verify-Handshake, Live-Status-Check) ausfallsicher in eine durchsuchbare DB-Tabelle und einen Log-Channel schreiben, sichtbar über eine Filament-Page.

**Architecture:** Ein zentraler `WebhookLogger`-Service schreibt DB-Zeile (`lead_webhook_logs`) + Channel-Zeile, vollständig in `try/catch` (darf nie den Webhook-Pfad brechen). Verdrahtet in `WebhookController` (alle Return-Pfade) und `FacebookGraphService` (subscribe/subscribed_apps). Anzeige via read-only Filament-Page `WebhookLogs`. Retention via `lead-pipeline:prune-webhook-logs` im Tages-Schedule.

**Tech Stack:** Laravel 12, Filament 3, Pest 4 + Orchestra Testbench, Spatie PackageServiceProvider. Arbeitsverzeichnis: `packages/john-wink/filament-lead-pipeline` (Plugin-Klon). Namespace-Root: `JohnWink\FilamentLeadPipeline`.

**Konventionen (verifiziert):**
- PK ist UUIDv7 via `HasConfigurablePrimaryKey` (`config('lead-pipeline.primary_key_type') === 'uuid'`).
- Migrationen: sequenzieller Prefix; nächste Nummer ist `0033_`. UUID-Spalten konditional wie in `0006`.
- Models nutzen `casts()`-Methode (nicht `$casts`), `BelongsToTeam`-Trait für `team()` + `scopeForTeam`.
- Enums: backed string, TitleCase-Cases, implementieren `HasColor`/`HasIcon`/`HasLabel`.
- `FacebookGraphService` ist Singleton, parameterloser Konstruktor, von allen Aufrufern über den Container bezogen → Logging via `app(WebhookLogger::class)` **innerhalb** der Methoden (keine Konstruktor-Änderung, kein Bruch).
- Tests: `vendor/bin/pest --parallel`; Pest erweitert `TestCase` + `RefreshDatabase`, seedet `TestSeeder`. Team via `Team::firstWhere('slug','test')`, Panel via `filament()->setCurrentPanel(...)`. HTTP-Webhook-Tests existieren bereits (`tests/Feature/WebhookHandleTest.php`, `WebhookMetaAttributionTest.php`) — gleiche Muster verwenden.
- Package-Migrationen laufen in Tests automatisch (über `loadMigrationsFrom` im ServiceProvider) → neue `0033`-Migration wird erfasst.

---

## File Structure

**Neu:**
- `src/Enums/WebhookLogEventType.php` — Ereignistyp-Enum
- `src/Models/LeadWebhookLog.php` — Append-only Log-Model
- `database/migrations/0033_create_lead_webhook_logs_table.php` — Tabelle
- `src/Services/WebhookLogger.php` — zentraler, ausfallsicherer Logger
- `src/Commands/PruneWebhookLogsCommand.php` — Retention-Prune
- `src/Filament/Pages/WebhookLogs.php` — read-only Viewer
- `resources/views/filament/pages/webhook-logs.blade.php` — Page-View
- Testdateien (s. u.)

**Geändert:**
- `config/lead-pipeline.php` — `webhooks.logging`-Block
- `src/Http/Controllers/WebhookController.php` — Logging an allen Return-Pfaden
- `src/Services/FacebookGraphService.php` — `subscribePageToLeadgen` + `getPageSubscribedApps`
- `src/FilamentLeadPipelineServiceProvider.php` — Logger-Binding, Command, Schedule, Log-Channel
- `src/Filament/Pages/SourceManagement.php` — Header-Action „Webhook-Protokoll"
- `src/FilamentLeadPipelinePlugin.php` — Page registrieren

---

## Task 1: Enum, Model & Migration (Fundament)

**Files:**
- Create: `src/Enums/WebhookLogEventType.php`
- Create: `src/Models/LeadWebhookLog.php`
- Create: `database/migrations/0033_create_lead_webhook_logs_table.php`
- Test: `tests/Feature/WebhookLogModelTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebhookLogModelTest.php`:
```php
<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

it('persists a webhook log with enum and array casts', function (): void {
    $log = LeadWebhookLog::create([
        'event_type'  => WebhookLogEventType::Incoming,
        'outcome'     => 'created',
        'http_status' => 201,
        'request'     => ['payload' => ['name' => 'Test']],
        'response'    => ['id' => 'abc'],
    ]);

    $fresh = $log->fresh();

    expect($fresh->getKey())->not->toBeNull()
        ->and($fresh->event_type)->toBe(WebhookLogEventType::Incoming)
        ->and($fresh->request)->toBe(['payload' => ['name' => 'Test']])
        ->and($fresh->response)->toBe(['id' => 'abc'])
        ->and($fresh->created_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=WebhookLogModelTest`
Expected: FAIL — `Class "JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType" not found`.

- [ ] **Step 3: Create the enum**

`src/Enums/WebhookLogEventType.php`:
```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WebhookLogEventType: string implements HasColor, HasLabel
{
    case Incoming     = 'incoming';
    case Registration = 'registration';
    case Verify       = 'verify';
    case StatusCheck  = 'status_check';

    public function getLabel(): string
    {
        return match ($this) {
            self::Incoming     => __('lead-pipeline::lead-pipeline.webhook_log.type.incoming'),
            self::Registration => __('lead-pipeline::lead-pipeline.webhook_log.type.registration'),
            self::Verify       => __('lead-pipeline::lead-pipeline.webhook_log.type.verify'),
            self::StatusCheck  => __('lead-pipeline::lead-pipeline.webhook_log.type.status_check'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Incoming     => 'info',
            self::Registration => 'primary',
            self::Verify       => 'gray',
            self::StatusCheck  => 'gray',
        };
    }
}
```

- [ ] **Step 4: Create the migration**

`database/migrations/0033_create_lead_webhook_logs_table.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lead_webhook_logs', function (Blueprint $table): void {
            $isUuid = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid');

            if ($isUuid) {
                $table->uuid('uuid')->primary();
            } else {
                $table->id();
            }

            if (config('lead-pipeline.tenancy.enabled', true)) {
                $teamFk = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
                if (str_contains($teamFk, 'uuid')) {
                    $table->uuid($teamFk)->nullable()->index();
                } else {
                    $table->unsignedBigInteger($teamFk)->nullable()->index();
                }
            }

            $table->uuid('lead_source_uuid')->nullable()->index();
            $table->uuid('facebook_page_uuid')->nullable()->index();
            $table->string('page_id')->nullable();
            $table->uuid('lead_uuid')->nullable();
            $table->string('event_type')->index();
            $table->string('driver')->nullable();
            $table->string('outcome')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('message')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_webhook_logs');
    }
};
```

- [ ] **Step 5: Create the model**

`src/Models/LeadWebhookLog.php`:
```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\BelongsToTeam;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;

class LeadWebhookLog extends Model
{
    use BelongsToTeam;
    use HasConfigurablePrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'lead_webhook_logs';

    protected $fillable = [
        'team_uuid',
        'lead_source_uuid',
        'facebook_page_uuid',
        'page_id',
        'lead_uuid',
        'event_type',
        'driver',
        'outcome',
        'http_status',
        'message',
        'request',
        'response',
    ];

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_uuid');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_uuid');
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_uuid');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => WebhookLogEventType::class,
            'request'    => 'array',
            'response'   => 'array',
            'created_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest --filter=WebhookLogModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Enums/WebhookLogEventType.php src/Models/LeadWebhookLog.php database/migrations/0033_create_lead_webhook_logs_table.php tests/Feature/WebhookLogModelTest.php
git commit -m "feat: add lead_webhook_logs table, model and event type enum"
```

---

## Task 2: WebhookLogger service + config block

**Files:**
- Modify: `config/lead-pipeline.php` (add `logging` to `webhooks`)
- Create: `src/Services/WebhookLogger.php`
- Modify: `src/FilamentLeadPipelineServiceProvider.php:51` (singleton binding)
- Test: `tests/Feature/WebhookLoggerTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebhookLoggerTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use JohnWink\FilamentLeadPipeline\Services\WebhookLogger;

it('records an incoming event and redacts sensitive keys', function (): void {
    $request = Request::create('/x', 'POST', ['name' => 'Test', 'api_token' => 'secret123']);

    app(WebhookLogger::class)->recordIncoming(null, $request, 'src-1', 'source_inactive', 404);

    $log = LeadWebhookLog::query()->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('source_inactive')
        ->and($log->http_status)->toBe(404)
        ->and($log->request['payload']['name'])->toBe('Test')
        ->and($log->request['payload']['api_token'])->toBe('[redacted]');
});

it('omits payloads when store_payload is disabled', function (): void {
    config()->set('lead-pipeline.webhooks.logging.store_payload', false);

    app(WebhookLogger::class)->recordIncoming(null, Request::create('/x', 'POST', ['a' => 'b']), 'src-1', 'created', 201);

    expect(LeadWebhookLog::query()->latest('created_at')->first()->request)->toBeNull();
});

it('is a no-op when logging is disabled', function (): void {
    config()->set('lead-pipeline.webhooks.logging.enabled', false);

    app(WebhookLogger::class)->recordIncoming(null, Request::create('/x', 'POST'), 'src-1', 'created', 201);

    expect(LeadWebhookLog::query()->count())->toBe(0);
});

it('never throws even if the log table is missing', function (): void {
    Schema::drop('lead_webhook_logs');

    app(WebhookLogger::class)->recordIncoming(null, Request::create('/x', 'POST'), 'src-1', 'created', 201);

    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=WebhookLoggerTest`
Expected: FAIL — `Class "JohnWink\FilamentLeadPipeline\Services\WebhookLogger" not found`.

- [ ] **Step 3: Add the config block**

In `config/lead-pipeline.php`, replace the `webhooks` block:
```php
    'webhooks' => [
        'prefix'     => 'api/lead-pipeline/webhooks',
        'middleware' => ['api'],
        'rate_limit' => 60,
    ],
```
with:
```php
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
```

- [ ] **Step 4: Create the WebhookLogger service**

`src/Services/WebhookLogger.php`:
```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use Throwable;

class WebhookLogger
{
    /** @var array<int, string> */
    private const REDACT_KEYS = [
        'access_token',
        'page_access_token',
        'authorization',
        'token',
        'api_token',
        'webhook_secret',
        'x-hub-signature-256',
    ];

    /** @param array<string, mixed>|null $response */
    public function recordIncoming(
        ?LeadSource $source,
        Request $request,
        string $sourceId,
        string $outcome,
        int $httpStatus,
        ?string $message = null,
        ?Lead $lead = null,
        ?array $response = null,
    ): void {
        $this->write([
            'event_type'       => WebhookLogEventType::Incoming,
            'team_uuid'        => $source?->team_uuid,
            'lead_source_uuid' => $source?->getKey(),
            'driver'           => $source?->driver,
            'lead_uuid'        => $lead?->getKey(),
            'outcome'          => $outcome,
            'http_status'      => $httpStatus,
            'message'          => $message,
            'request'          => ['source_id' => $sourceId, 'payload' => $request->all()],
            'response'         => $response,
        ]);
    }

    /**
     * @param  array<string, mixed>       $request
     * @param  array<string, mixed>|null  $response
     */
    public function recordRegistration(string $pageId, array $request, ?array $response, bool $success, ?string $message = null): void
    {
        $page = rescue(fn (): ?FacebookPage => FacebookPage::query()->where('page_id', $pageId)->first(), null, false);

        $this->write([
            'event_type'         => WebhookLogEventType::Registration,
            'team_uuid'          => $page?->connection?->team_uuid,
            'facebook_page_uuid' => $page?->getKey(),
            'page_id'            => $pageId,
            'driver'             => 'meta',
            'outcome'            => $success ? 'subscribed' : 'subscribe_failed',
            'message'            => $message,
            'request'            => $request,
            'response'           => $response,
        ]);
    }

    public function recordVerify(?LeadSource $source, ?string $pageId, bool $ok, ?string $message = null): void
    {
        $this->write([
            'event_type'       => WebhookLogEventType::Verify,
            'team_uuid'        => $source?->team_uuid,
            'lead_source_uuid' => $source?->getKey(),
            'page_id'          => $pageId,
            'driver'           => 'meta',
            'outcome'          => $ok ? 'verified' : 'verify_failed',
            'message'          => $message,
        ]);
    }

    /** @param array<string, mixed>|null $response */
    public function recordStatusCheck(string $pageId, ?array $response, bool $ok, ?string $message = null): void
    {
        $page = rescue(fn (): ?FacebookPage => FacebookPage::query()->where('page_id', $pageId)->first(), null, false);

        $this->write([
            'event_type'         => WebhookLogEventType::StatusCheck,
            'team_uuid'          => $page?->connection?->team_uuid,
            'facebook_page_uuid' => $page?->getKey(),
            'page_id'            => $pageId,
            'driver'             => 'meta',
            'outcome'            => $ok ? 'ok' : 'error',
            'message'            => $message,
            'response'           => $response,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function write(array $attributes): void
    {
        if ( ! config('lead-pipeline.webhooks.logging.enabled', true)) {
            return;
        }

        try {
            if ( ! config('lead-pipeline.webhooks.logging.store_payload', true)) {
                $attributes['request']  = null;
                $attributes['response'] = null;
            } else {
                $attributes['request']  = isset($attributes['request']) && is_array($attributes['request'])
                    ? $this->redact($attributes['request'])
                    : null;
                $attributes['response'] = isset($attributes['response']) && is_array($attributes['response'])
                    ? $this->redact($attributes['response'])
                    : null;
            }

            LeadWebhookLog::create($attributes);

            $eventType = $attributes['event_type'];

            Log::channel(config('lead-pipeline.webhooks.logging.channel', 'lead-webhooks'))->info('webhook', [
                'event_type'  => $eventType instanceof WebhookLogEventType ? $eventType->value : $eventType,
                'outcome'     => $attributes['outcome'] ?? null,
                'http_status' => $attributes['http_status'] ?? null,
                'source'      => $attributes['lead_source_uuid'] ?? null,
                'page_id'     => $attributes['page_id'] ?? null,
                'message'     => $attributes['message'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);

                continue;
            }

            if (in_array(mb_strtolower((string) $key), self::REDACT_KEYS, true)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
```

- [ ] **Step 5: Bind the logger as a singleton**

In `src/FilamentLeadPipelineServiceProvider.php`, inside `packageRegistered()`, after the line:
```php
        $this->app->singleton(Services\FacebookGraphService::class);
```
add:
```php
        $this->app->singleton(Services\WebhookLogger::class);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest --filter=WebhookLoggerTest`
Expected: PASS (all 4 cases).

- [ ] **Step 7: Commit**

```bash
git add config/lead-pipeline.php src/Services/WebhookLogger.php src/FilamentLeadPipelineServiceProvider.php tests/Feature/WebhookLoggerTest.php
git commit -m "feat: add fail-safe WebhookLogger service and logging config"
```

---

## Task 3: Wire incoming logging into WebhookController::handle()

**Files:**
- Modify: `src/Http/Controllers/WebhookController.php` (constructor + `handle()`)
- Test: `tests/Feature/WebhookLoggingIncomingTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebhookLoggingIncomingTest.php`:
```php
<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);
    $this->source = LeadSource::factory()
        ->for($this->board, 'board')
        ->active()
        ->withApiToken()
        ->create(['team_uuid' => $this->team->uuid]);
    $this->url = '/' . config('lead-pipeline.webhooks.prefix') . '/' . $this->source->getKey();
});

it('logs a created incoming webhook', function (): void {
    $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->url, ['name' => 'Logged Lead', 'email' => 'logged@example.com'])
        ->assertCreated();

    $log = LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Incoming)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('created')
        ->and($log->http_status)->toBe(201)
        ->and($log->lead_source_uuid)->toBe($this->source->getKey())
        ->and($log->lead_uuid)->not->toBeNull();
});

it('logs a rejected signature', function (): void {
    $this->withHeader('Authorization', 'Bearer wrong')
        ->postJson($this->url, ['name' => 'X'])
        ->assertForbidden();

    expect(LeadWebhookLog::query()->where('outcome', 'rejected_signature')->where('http_status', 403)->exists())->toBeTrue();
});

it('logs an inactive source', function (): void {
    $this->source->update(['status' => LeadSourceStatusEnum::Paused]);

    $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->url, ['name' => 'X'])
        ->assertNotFound();

    expect(LeadWebhookLog::query()->where('outcome', 'source_inactive')->where('http_status', 404)->exists())->toBeTrue();
});

it('logs a missing phase', function (): void {
    $this->board->phases()->delete();

    $this->withHeader('Authorization', 'Bearer ' . $this->source->api_token)
        ->postJson($this->url, ['name' => 'X'])
        ->assertStatus(422);

    expect(LeadWebhookLog::query()->where('outcome', 'no_phase')->where('http_status', 422)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=WebhookLoggingIncomingTest`
Expected: FAIL — no `lead_webhook_logs` rows / assertions false.

- [ ] **Step 3: Add the logger to the controller constructor**

In `src/Http/Controllers/WebhookController.php`, add the import after the existing `use ...LeadSourceManager;` line:
```php
use JohnWink\FilamentLeadPipeline\Services\WebhookLogger;
```
and replace the constructor:
```php
    public function __construct(
        public LeadSourceManager $manager,
    ) {}
```
with:
```php
    public function __construct(
        public LeadSourceManager $manager,
        public WebhookLogger $logger,
    ) {}
```

- [ ] **Step 4: Instrument every return point in `handle()`**

Replace the 404 block:
```php
        if ( ! $source) {
            return response()->json(['error' => 'Source not found or inactive.'], 404);
        }
```
with:
```php
        if ( ! $source) {
            $this->logger->recordIncoming(null, $request, $sourceId, 'source_inactive', 404);

            return response()->json(['error' => 'Source not found or inactive.'], 404);
        }
```

Replace the 403 block:
```php
            if ( ! $driver->verifySignature($request->getContent(), $signatureValue, $source)) {
                return response()->json(['error' => 'Invalid signature.'], 403);
            }
```
with:
```php
            if ( ! $driver->verifySignature($request->getContent(), $signatureValue, $source)) {
                $this->logger->recordIncoming($source, $request, $sourceId, 'rejected_signature', 403);

                return response()->json(['error' => 'Invalid signature.'], 403);
            }
```

Replace the 422 block:
```php
            if ( ! $targetPhase) {
                return response()->json(['error' => 'No suitable phase found on board.'], 422);
            }
```
with:
```php
            if ( ! $targetPhase) {
                $this->logger->recordIncoming($source, $request, $sourceId, 'no_phase', 422);

                return response()->json(['error' => 'No suitable phase found on board.'], 422);
            }
```

Replace the success block:
```php
            LeadCreated::dispatch($lead);

            return response()->json(['id' => $lead->getKey()], 201);
        } catch (Exception $e) {
            $source->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Internal server error.'], 500);
        }
```
with:
```php
            LeadCreated::dispatch($lead);

            $this->logger->recordIncoming($source, $request, $sourceId, 'created', 201, null, $lead);

            return response()->json(['id' => $lead->getKey()], 201);
        } catch (Exception $e) {
            $source->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => $e->getMessage(),
            ]);

            $this->logger->recordIncoming($source, $request, $sourceId, 'processing_error', 500, $e->getMessage());

            return response()->json(['error' => 'Internal server error.'], 500);
        }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest --filter=WebhookLoggingIncomingTest`
Expected: PASS (all 4 cases).

- [ ] **Step 6: Run the existing webhook suite (no regression)**

Run: `vendor/bin/pest --filter=WebhookHandleTest`
Expected: PASS (unchanged behaviour, only added logging).

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/WebhookController.php tests/Feature/WebhookLoggingIncomingTest.php
git commit -m "feat: log every outcome of the generic webhook handler"
```

---

## Task 4: Wire Meta-central + verify endpoints

**Files:**
- Modify: `src/Http/Controllers/WebhookController.php` (`handleMetaCentral`, `verifyMetaCentral`, `verifyMeta`)
- Test: `tests/Feature/WebhookLoggingMetaTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebhookLoggingMetaTest.php`:
```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

beforeEach(function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'test-app-secret');
    config()->set('lead-pipeline.facebook.verify_token', 'verify-me');

    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Open, 'sort' => 0]);

    $connection = FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-1',
        'facebook_user_name' => 'Tester',
        'access_token'       => 'token',
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    $this->fbPage = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-100',
        'page_name'                => 'Test Page',
        'page_access_token'        => 'page-token',
        'is_webhooks_subscribed'   => true,
    ]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'Meta Source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $this->fbPage->uuid,
        'facebook_form_ids'                => ['form-1'],
    ]);

    $this->base = '/' . config('lead-pipeline.webhooks.prefix');
});

it('logs an incoming meta lead as created', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-789*' => Http::response([
            'id'         => 'lead-789',
            'form_id'    => 'form-1',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Anna Beispiel']],
                ['name' => 'email', 'values' => ['anna@example.com']],
            ],
        ]),
    ]);

    $payload = ['entry' => [[
        'id'      => 'page-100',
        'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'lead-789', 'form_id' => 'form-1', 'page_id' => 'page-100']]],
    ]]];
    $content = json_encode($payload);

    $this->withHeader('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $content, 'test-app-secret'))
        ->postJson($this->base . '/meta', $payload)
        ->assertOk();

    $log = LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Incoming)->where('outcome', 'created')->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->lead_source_uuid)->toBe($this->source->getKey())
        ->and($log->lead_uuid)->not->toBeNull();
});

it('logs a verify handshake for the central endpoint', function (): void {
    $this->get($this->base . '/meta?hub_verify_token=verify-me&hub_challenge=PING')
        ->assertOk()
        ->assertSee('PING');

    expect(LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Verify)->where('outcome', 'verified')->exists())->toBeTrue();
});

it('logs a failed verify handshake', function (): void {
    $this->get($this->base . '/meta?hub_verify_token=wrong&hub_challenge=PING')
        ->assertForbidden();

    expect(LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Verify)->where('outcome', 'verify_failed')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=WebhookLoggingMetaTest`
Expected: FAIL — verify/incoming logs missing.

- [ ] **Step 3: Instrument `handleMetaCentral()`**

In `src/Http/Controllers/WebhookController.php`, in `handleMetaCentral()`, replace the signature-fail block:
```php
        if ( ! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature.'], 403);
        }
```
with:
```php
        if ( ! hash_equals($expected, $signature)) {
            $this->logger->recordIncoming(null, $request, 'meta-central', 'rejected_signature', 403);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }
```

Replace the page-not-found block:
```php
            $fbPage = FacebookPage::query()->where('page_id', $pageId)->first();

            if ( ! $fbPage) {
                continue;
            }
```
with:
```php
            $fbPage = FacebookPage::query()->where('page_id', $pageId)->first();

            if ( ! $fbPage) {
                $this->logger->recordIncoming(null, $request, 'meta-central', 'skipped', 200, "page_not_found:{$pageId}");

                continue;
            }
```

Replace the form-not-mapped block:
```php
                if ($sources->isEmpty()) {
                    continue;
                }
```
with:
```php
                if ($sources->isEmpty()) {
                    $this->logger->recordIncoming(null, $request, 'meta-central', 'skipped', 200, "form_not_mapped:{$formId}");

                    continue;
                }
```

Replace the token-invalid catch block:
```php
                } catch (FacebookTokenInvalidException $e) {
                    $connection = $fbPage->connection;
                    if ($connection) {
                        $this->markConnectionNeedsReauth($connection, $e->getMessage());
                    }

                    // ACK (200 at the end) so Facebook stops retrying — prevents the 36h
                    // retry cascade + webhook subscription disablement. Missed leads are
                    // backfilled via ImportFacebookLeadsJob after reconnect.
                    continue;
                }
```
with:
```php
                } catch (FacebookTokenInvalidException $e) {
                    $connection = $fbPage->connection;
                    if ($connection) {
                        $this->markConnectionNeedsReauth($connection, $e->getMessage());
                    }

                    $this->logger->recordIncoming(null, $request, 'meta-central', 'skipped', 200, 'token_invalid: ' . $e->getMessage());

                    // ACK (200 at the end) so Facebook stops retrying — prevents the 36h
                    // retry cascade + webhook subscription disablement. Missed leads are
                    // backfilled via ImportFacebookLeadsJob after reconnect.
                    continue;
                }
```

Replace the per-source create block:
```php
                foreach ($sources as $source) {
                    try {
                        $lead = $this->createLeadFromFacebookData($source, $fieldData, $leadData);
                        if ($lead->wasRecentlyCreated) {
                            $leadsCreated[] = $lead->getKey();
                        }
                    } catch (Exception $e) {
                        $source->update([
                            'status'        => LeadSourceStatusEnum::Error,
                            'error_message' => $e->getMessage(),
                        ]);
                    }
                }
```
with:
```php
                foreach ($sources as $source) {
                    try {
                        $lead = $this->createLeadFromFacebookData($source, $fieldData, $leadData);
                        if ($lead->wasRecentlyCreated) {
                            $leadsCreated[] = $lead->getKey();
                        }
                        $this->logger->recordIncoming(
                            $source,
                            $request,
                            'meta-central',
                            $lead->wasRecentlyCreated ? 'created' : 'skipped',
                            200,
                            $lead->wasRecentlyCreated ? null : 'duplicate_external_id',
                            $lead,
                        );
                    } catch (Exception $e) {
                        $source->update([
                            'status'        => LeadSourceStatusEnum::Error,
                            'error_message' => $e->getMessage(),
                        ]);

                        $this->logger->recordIncoming($source, $request, 'meta-central', 'processing_error', 500, $e->getMessage());
                    }
                }
```

- [ ] **Step 4: Instrument `verifyMetaCentral()`**

Replace the entire method:
```php
    public function verifyMetaCentral(Request $request): Response
    {
        $verifyToken  = config('lead-pipeline.facebook.verify_token');
        $hubToken     = $request->query('hub_verify_token', '');
        $hubChallenge = $request->query('hub_challenge', '');

        if ('' === $hubToken || $hubToken !== $verifyToken) {
            return response('Invalid verify token.', 403);
        }

        return response((string) $hubChallenge, 200);
    }
```
with:
```php
    public function verifyMetaCentral(Request $request): Response
    {
        $verifyToken  = config('lead-pipeline.facebook.verify_token');
        $hubToken     = $request->query('hub_verify_token', '');
        $hubChallenge = $request->query('hub_challenge', '');

        if ('' === $hubToken || $hubToken !== $verifyToken) {
            $this->logger->recordVerify(null, null, false, 'central: token mismatch');

            return response('Invalid verify token.', 403);
        }

        $this->logger->recordVerify(null, null, true, 'central');

        return response((string) $hubChallenge, 200);
    }
```

- [ ] **Step 5: Instrument `verifyMeta()`**

Replace the entire method:
```php
    public function verifyMeta(Request $request, string $sourceId): Response
    {
        $source = LeadSource::query()
            ->where(LeadSource::pkColumn(), $sourceId)
            ->first();

        if ( ! $source) {
            return response('Source not found.', 404);
        }

        $hubVerifyToken = $request->query('hub_verify_token', '');
        $hubChallenge   = $request->query('hub_challenge', '');

        $expectedToken = $source->config['verify_token'] ?? '';

        if ('' === $hubVerifyToken || $hubVerifyToken !== $expectedToken) {
            return response('Invalid verify token.', 403);
        }

        return response((string) $hubChallenge, 200);
    }
```
with:
```php
    public function verifyMeta(Request $request, string $sourceId): Response
    {
        $source = LeadSource::query()
            ->where(LeadSource::pkColumn(), $sourceId)
            ->first();

        if ( ! $source) {
            $this->logger->recordVerify(null, null, false, "source_not_found:{$sourceId}");

            return response('Source not found.', 404);
        }

        $hubVerifyToken = $request->query('hub_verify_token', '');
        $hubChallenge   = $request->query('hub_challenge', '');

        $expectedToken = $source->config['verify_token'] ?? '';

        if ('' === $hubVerifyToken || $hubVerifyToken !== $expectedToken) {
            $this->logger->recordVerify($source, null, false, 'per-source: token mismatch');

            return response('Invalid verify token.', 403);
        }

        $this->logger->recordVerify($source, null, true, 'per-source');

        return response((string) $hubChallenge, 200);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest --filter=WebhookLoggingMetaTest`
Expected: PASS (3 cases).

Run: `vendor/bin/pest --filter=WebhookMetaAttributionTest`
Expected: PASS (no regression).

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/WebhookController.php tests/Feature/WebhookLoggingMetaTest.php
git commit -m "feat: log meta-central webhook outcomes and verify handshakes"
```

---

## Task 5: Wire registration + status-check logging into FacebookGraphService

**Files:**
- Modify: `src/Services/FacebookGraphService.php` (`subscribePageToLeadgen`, `getPageSubscribedApps`)
- Test: `tests/Feature/WebhookLoggingRegistrationTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebhookLoggingRegistrationTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

it('logs a successful subscription with the full FB response', function (): void {
    Http::fake([
        'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
    ]);

    app(FacebookGraphService::class)->subscribePageToLeadgen('page-1', 'page-token');

    $log = LeadWebhookLog::query()
        ->where('event_type', WebhookLogEventType::Registration)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('subscribed')
        ->and($log->page_id)->toBe('page-1')
        ->and($log->response)->toBe(['success' => true]);
});

it('logs a failed subscription with the FB error body', function (): void {
    Http::fake([
        'graph.facebook.com/*/subscribed_apps' => Http::response([
            'error' => ['message' => 'No permission', 'code' => 200, 'type' => 'OAuthException'],
        ], 403),
    ]);

    try {
        app(FacebookGraphService::class)->subscribePageToLeadgen('page-2', 'page-token');
    } catch (Throwable) {
        // classifyError rethrows — expected
    }

    $log = LeadWebhookLog::query()->where('outcome', 'subscribe_failed')->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->page_id)->toBe('page-2')
        ->and($log->response['error']['message'])->toBe('No permission');
});

it('logs a live status check from getPageSubscribedApps', function (): void {
    Http::fake([
        'graph.facebook.com/*/subscribed_apps' => Http::response([
            'data' => [['id' => 'app-1', 'subscribed_fields' => ['leadgen']]],
        ]),
    ]);

    app(FacebookGraphService::class)->getPageSubscribedApps('page-3', 'page-token');

    expect(
        LeadWebhookLog::query()
            ->where('event_type', WebhookLogEventType::StatusCheck)
            ->where('outcome', 'ok')
            ->where('page_id', 'page-3')
            ->exists()
    )->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=WebhookLoggingRegistrationTest`
Expected: FAIL — no registration/status logs written.

- [ ] **Step 3: Add the import**

In `src/Services/FacebookGraphService.php`, add to the use-statements:
```php
use JohnWink\FilamentLeadPipeline\Services\WebhookLogger;
```

- [ ] **Step 4: Instrument `subscribePageToLeadgen()`**

Replace the method:
```php
    public function subscribePageToLeadgen(string $pageId, string $pageAccessToken): bool
    {
        $response = $this->client()->post("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token'      => $pageAccessToken,
            'subscribed_fields' => 'leadgen',
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to subscribe to leadgen');
        }

        return $response->json('success', false);
    }
```
with:
```php
    public function subscribePageToLeadgen(string $pageId, string $pageAccessToken): bool
    {
        $response = $this->client()->post("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token'      => $pageAccessToken,
            'subscribed_fields' => 'leadgen',
        ]);

        if ($response->failed()) {
            app(WebhookLogger::class)->recordRegistration(
                $pageId,
                ['subscribed_fields' => 'leadgen'],
                (array) $response->json(),
                false,
                $response->json('error.message') ?? $response->body(),
            );

            throw $this->classifyError($response, 'Failed to subscribe to leadgen');
        }

        app(WebhookLogger::class)->recordRegistration(
            $pageId,
            ['subscribed_fields' => 'leadgen'],
            (array) $response->json(),
            true,
        );

        return $response->json('success', false);
    }
```

- [ ] **Step 5: Instrument `getPageSubscribedApps()`**

Replace the method:
```php
    public function getPageSubscribedApps(string $pageId, string $pageAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token' => $pageAccessToken,
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch subscribed apps');
        }

        return $response->json('data', []);
    }
```
with:
```php
    public function getPageSubscribedApps(string $pageId, string $pageAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token' => $pageAccessToken,
        ]);

        if ($response->failed()) {
            app(WebhookLogger::class)->recordStatusCheck(
                $pageId,
                (array) $response->json(),
                false,
                $response->json('error.message') ?? $response->body(),
            );

            throw $this->classifyError($response, 'Failed to fetch subscribed apps');
        }

        app(WebhookLogger::class)->recordStatusCheck($pageId, (array) $response->json(), true);

        return $response->json('data', []);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest --filter=WebhookLoggingRegistrationTest`
Expected: PASS (3 cases).

Run: `vendor/bin/pest --filter=FacebookGraph`
Expected: PASS (existing Graph tests unaffected).

- [ ] **Step 7: Commit**

```bash
git add src/Services/FacebookGraphService.php tests/Feature/WebhookLoggingRegistrationTest.php
git commit -m "feat: log facebook leadgen subscription and subscribed-apps responses"
```

---

## Task 6: Prune command + schedule

**Files:**
- Create: `src/Commands/PruneWebhookLogsCommand.php`
- Modify: `src/FilamentLeadPipelineServiceProvider.php` (hasCommands + boot schedule + log channel)
- Test: `tests/Feature/PruneWebhookLogsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/PruneWebhookLogsTest.php`:
```php
<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

it('prunes logs older than the retention window', function (): void {
    config()->set('lead-pipeline.webhooks.logging.retention_days', 30);

    $old = LeadWebhookLog::create(['event_type' => WebhookLogEventType::Incoming, 'outcome' => 'created']);
    $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

    $new = LeadWebhookLog::create(['event_type' => WebhookLogEventType::Incoming, 'outcome' => 'created']);
    $new->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

    $this->artisan('lead-pipeline:prune-webhook-logs')->assertSuccessful();

    expect(LeadWebhookLog::query()->count())->toBe(1)
        ->and(LeadWebhookLog::query()->first()->getKey())->toBe($new->getKey());
});

it('accepts a --days override', function (): void {
    $log = LeadWebhookLog::create(['event_type' => WebhookLogEventType::Incoming, 'outcome' => 'created']);
    $log->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('lead-pipeline:prune-webhook-logs', ['--days' => 7])->assertSuccessful();

    expect(LeadWebhookLog::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=PruneWebhookLogsTest`
Expected: FAIL — `Command "lead-pipeline:prune-webhook-logs" is not defined`.

- [ ] **Step 3: Create the command**

`src/Commands/PruneWebhookLogsCommand.php`:
```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

class PruneWebhookLogsCommand extends Command
{
    protected $signature = 'lead-pipeline:prune-webhook-logs
        {--days= : Aufbewahrungsfrist in Tagen (überschreibt die Config)}';

    protected $description = 'Löscht Webhook-Logs, die älter als die konfigurierte Aufbewahrungsfrist sind';

    public function handle(): int
    {
        $days   = (int) ($this->option('days') ?? config('lead-pipeline.webhooks.logging.retention_days', 30));
        $cutoff = now()->subDays($days);

        $deleted = LeadWebhookLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Gelöscht: {$deleted} Webhook-Logs älter als {$days} Tage.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the command**

In `src/FilamentLeadPipelineServiceProvider.php`, inside `configurePackage()`, in the `->hasCommands([...])` array, after:
```php
                Commands\SendLeadRemindersCommand::class,
```
add:
```php
                Commands\PruneWebhookLogsCommand::class,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest --filter=PruneWebhookLogsTest`
Expected: PASS (2 cases).

- [ ] **Step 6: Register the log channel + schedule in boot()**

In `src/FilamentLeadPipelineServiceProvider.php`, inside `boot()`, immediately after `parent::boot();`, add:
```php
        if ( ! config()->has('logging.channels.lead-webhooks')) {
            config(['logging.channels.lead-webhooks' => [
                'driver' => 'daily',
                'path'   => storage_path('logs/lead-webhooks.log'),
                'level'  => 'debug',
                'days'   => (int) config('lead-pipeline.webhooks.logging.retention_days', 30),
            ]]);
        }

        if (config('lead-pipeline.webhooks.logging.enabled', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(Commands\PruneWebhookLogsCommand::class)
                    ->dailyAt('04:30')->withoutOverlapping()->onOneServer();
            });
        }
```

- [ ] **Step 7: Run the full suite (channel/schedule must not break boot)**

Run: `vendor/bin/pest --parallel`
Expected: PASS (entire suite green).

- [ ] **Step 8: Commit**

```bash
git add src/Commands/PruneWebhookLogsCommand.php src/FilamentLeadPipelineServiceProvider.php tests/Feature/PruneWebhookLogsTest.php
git commit -m "feat: add webhook-log retention command, schedule and log channel"
```

---

## Task 7: Filament viewer page

**Files:**
- Create: `src/Filament/Pages/WebhookLogs.php`
- Create: `resources/views/filament/pages/webhook-logs.blade.php`
- Modify: `src/FilamentLeadPipelinePlugin.php:345` (register page)
- Modify: `src/Filament/Pages/SourceManagement.php` (header action)
- Test: `tests/Feature/WebhookLogsPageTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebhookLogsPageTest.php`:
```php
<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Filament\Pages\WebhookLogs;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('renders the webhook logs page', function (): void {
    livewire(WebhookLogs::class)->assertSuccessful();
});

it('shows webhook logs for the current tenant', function (): void {
    $log = LeadWebhookLog::create([
        'team_uuid'   => $this->team->uuid,
        'event_type'  => WebhookLogEventType::Incoming,
        'outcome'     => 'created',
        'http_status' => 201,
    ]);

    livewire(WebhookLogs::class)->assertCanSeeTableRecords([$log]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest --filter=WebhookLogsPageTest`
Expected: FAIL — `Class "JohnWink\FilamentLeadPipeline\Filament\Pages\WebhookLogs" not found`.

- [ ] **Step 3: Create the page**

`src/Filament/Pages/WebhookLogs.php`:
```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Filament\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

class WebhookLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string $view = 'lead-pipeline::filament.pages.webhook-logs';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('lead-pipeline::lead-pipeline.webhook_log.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LeadWebhookLog::query()
                    ->when(
                        filament()->getTenant(),
                        fn ($q) => $q->forTeam(filament()->getTenant()->getKey()),
                    )
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.received_at'))
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.event_type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('driver')
                    ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('outcome')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.outcome'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created', 'subscribed', 'verified', 'ok' => 'success',
                        'skipped'                                  => 'gray',
                        'rejected_signature', 'subscribe_failed', 'verify_failed', 'source_inactive', 'no_phase', 'processing_error', 'error' => 'danger',
                        default                                    => 'warning',
                    }),
                Tables\Columns\TextColumn::make('http_status')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.http_status'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('page_id')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.page_id'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.message'))
                    ->limit(60)
                    ->tooltip(fn (LeadWebhookLog $record): ?string => $record->message)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.event_type'))
                    ->options(WebhookLogEventType::class),
                Tables\Filters\SelectFilter::make('driver')
                    ->label(__('lead-pipeline::lead-pipeline.field.driver'))
                    ->options(['api' => 'API', 'zapier' => 'Zapier', 'meta' => 'Meta', 'funnel' => 'Funnel']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label(__('lead-pipeline::lead-pipeline.webhook_log.details'))
                    ->modalHeading(__('lead-pipeline::lead-pipeline.webhook_log.details'))
                    ->infolist([
                        TextEntry::make('outcome')->badge(),
                        TextEntry::make('message')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('request')
                            ->label('Request')
                            ->columnSpanFull()
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—'),
                        TextEntry::make('response')
                            ->label('Response')
                            ->columnSpanFull()
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—'),
                    ]),
            ]);
    }
}
```

- [ ] **Step 4: Create the page view**

`resources/views/filament/pages/webhook-logs.blade.php`:
```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

- [ ] **Step 5: Register the page in the plugin**

In `src/FilamentLeadPipelinePlugin.php`, in the `->pages([...])` array, after:
```php
                Filament\Pages\SourceManagement::class,
```
add:
```php
                Filament\Pages\WebhookLogs::class,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest --filter=WebhookLogsPageTest`
Expected: PASS (2 cases).

- [ ] **Step 7: Add the header action on SourceManagement**

In `src/Filament/Pages/SourceManagement.php`, add this method right after `getTitle()`:
```php
    /** @return array<\Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('webhook_logs')
                ->label(__('lead-pipeline::lead-pipeline.webhook_log.title'))
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('gray')
                ->url(WebhookLogs::getUrl()),
        ];
    }
```
(`WebhookLogs` is in the same `JohnWink\FilamentLeadPipeline\Filament\Pages` namespace — no import needed.)

- [ ] **Step 8: Run the SourceManagement suite (no regression)**

Run: `vendor/bin/pest --filter=SourceManagementTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Filament/Pages/WebhookLogs.php resources/views/filament/pages/webhook-logs.blade.php src/FilamentLeadPipelinePlugin.php src/Filament/Pages/SourceManagement.php tests/Feature/WebhookLogsPageTest.php
git commit -m "feat: add read-only webhook log viewer page"
```

---

## Task 8: Translations, full suite, format, release

**Files:**
- Modify: `lang/de/lead-pipeline.php` and `lang/en/lead-pipeline.php` (translation keys)
- All previously created/modified files

- [ ] **Step 1: Add translation keys**

Locate the translation files (`lang/de/lead-pipeline.php`, `lang/en/lead-pipeline.php` — confirm exact path with `ls lang/*/lead-pipeline.php`). Add a `webhook_log` block to each. German (`lang/de/lead-pipeline.php`), inside the returned array:
```php
    'webhook_log' => [
        'title'       => 'Webhook-Protokoll',
        'received_at' => 'Zeitpunkt',
        'event_type'  => 'Ereignis',
        'outcome'     => 'Ergebnis',
        'http_status' => 'HTTP',
        'page_id'     => 'Page-ID',
        'message'     => 'Meldung',
        'details'     => 'Details',
        'type'        => [
            'incoming'     => 'Eingehend',
            'registration' => 'Registrierung',
            'verify'       => 'Verify',
            'status_check' => 'Status-Check',
        ],
    ],
```
English (`lang/en/lead-pipeline.php`):
```php
    'webhook_log' => [
        'title'       => 'Webhook log',
        'received_at' => 'Timestamp',
        'event_type'  => 'Event',
        'outcome'     => 'Outcome',
        'http_status' => 'HTTP',
        'page_id'     => 'Page ID',
        'message'     => 'Message',
        'details'     => 'Details',
        'type'        => [
            'incoming'     => 'Incoming',
            'registration' => 'Registration',
            'verify'       => 'Verify',
            'status_check' => 'Status check',
        ],
    ],
```

- [ ] **Step 2: Run the full suite**

Run: `vendor/bin/pest --parallel`
Expected: PASS (whole suite green, including the new tests).

- [ ] **Step 3: Static analysis + format**

Run: `composer analyse` (PHPStan/Larastan) — fix any reported issues in the new files.
Run: `vendor/bin/pint` — auto-format.

- [ ] **Step 4: Commit translations + formatting**

```bash
git add lang/de/lead-pipeline.php lang/en/lead-pipeline.php
git commit -m "feat: add webhook log translations"
```

- [ ] **Step 5: Tag the release**

```bash
git tag v0.1.46
git push origin HEAD --tags
```
(Respect the GitButler workflow when committing/pushing in this repo.)

- [ ] **Step 6: Pull the release into the host app**

In `/Users/johnwink/Herd/finance-estate`:
```bash
composer update john-wink/filament-lead-pipeline
php artisan migrate
php artisan filament:optimize
```
Verify the new route/page: `php artisan migrate:status` shows `0033_create_lead_webhook_logs_table`, and `lead_webhook_logs` exists. On Vapor, the migration runs via the deploy pipeline.

- [ ] **Step 7: Smoke-check on the host**

Open any panel → Lead Boards → „Quellen verwalten" → header action „Webhook-Protokoll" renders the (empty) table. Trigger a test webhook (or the `lead-pipeline:facebook-webhook-status` command, which now writes `status_check` rows) and confirm log rows appear.

---

## Self-Review

- **Spec coverage:** Tabelle (T1) ✓, Enum (T1) ✓, `WebhookLogger` + Redaction + try/catch + store_payload + enabled (T2) ✓, incoming alle Return-Pfade (T3) ✓, meta-central + verify (T4) ✓, Registrierung volle FB-Antwort + status_check (T5) ✓, Retention-Command + Schedule + Log-Channel (T6) ✓, Filament-Viewer + Header-Action + Page-Registrierung (T7) ✓, Config-Block (T2) ✓, Übersetzungen (T8) ✓, Release/Host (T8) ✓.
- **Naming consistency:** Enum `WebhookLogEventType` (Incoming/Registration/Verify/StatusCheck); model `LeadWebhookLog`/table `lead_webhook_logs`; service `WebhookLogger` mit `recordIncoming/recordRegistration/recordVerify/recordStatusCheck`; outcomes (created/rejected_signature/source_inactive/no_phase/processing_error/skipped/subscribed/subscribe_failed/verified/verify_failed/ok) durchgängig zwischen Logger, Controller, GraphService und Page-Badge-Farben verwendet. ✓
- **Open assumptions to verify during execution:** (a) exact path of the translation files in Step 8.1 (`ls lang`); (b) `FacebookPage::connection` relation name (used in WebhookLogger team resolution — confirmed via SourceManagement `facebookPage.connection` eager-load); (c) `->pages([...])` block in `FilamentLeadPipelinePlugin.php` is wrapped in a `hasSourceManagement` condition — add `WebhookLogs::class` inside the same array so it shares the toggle.
