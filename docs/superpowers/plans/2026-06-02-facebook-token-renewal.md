# Facebook Token Renewal & Paragon-Härtung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Facebook long-lived-token renewal self-healing (survive transient failures, only require manual re-auth on terminal failures), detect token death across all consumption paths, expose token health + a one-click reconnect in the UI, and harden the related webhook/lead paths to paragon quality.

**Architecture:** Typed Graph exceptions (terminal vs transient) drive a per-connection refresh job with exponential backoff and escalation; a synchronous scheduled command scans due connections (inline or queued) and fires domain **events** (the package never sends notifications itself). Token death surfaces from sync/import/webhook into a single `NeedsReauth` state with a guided reconnect action.

**Tech Stack:** Laravel 12, Filament 3, PHP 8.4, Pest 4 (run with `--parallel`), Spatie laravel-package-tools, Testbench. Package namespace `JohnWink\FilamentLeadPipeline\`. Tests load host tables from `tests/Fixtures/migrations/0000_create_test_tables.php`; package tables (incl. new migrations) load via the provider's `loadMigrationsFrom`. Tests seed a `test` team via `TestSeeder` in `beforeEach`.

**Test command convention:** Always run with `--parallel`. Single file during TDD: `./vendor/bin/pest tests/Feature/<File>.php --parallel`. Filter: `./vendor/bin/pest --parallel --filter="<name>"`. Full suite: `./vendor/bin/pest --parallel`. Format before commit: `vendor/bin/pint --dirty --format agent`.

**Scope deviation from spec (deliberate):** The spec's webhook auth change to `verifyRequest(Request, LeadSource)` is **dropped**. The bearer-vs-HMAC blur was verified non-exploitable (a bearer can never satisfy `hash_equals(HMAC, …)` without the app secret; per-source token auth and central HMAC auth are already separated by route/handler), and the change would break the public `LeadSourceDriver` contract plus 6 existing driver-level tests in `WebhookHandleTest.php`. We keep `verifySignature`. The real webhook bugs (idempotency, ACK-on-token-death, token-death detection) ARE fixed (Tasks 13–14).

**Two USER-CONTRIBUTION points** (domain policy — leave clearly marked for the package author): `FacebookGraphService::classifyError()` (Task 2) and `RefreshFacebookConnection::shouldEscalate()` + backoff params (Task 7). Sensible defaults are provided; the author refines the ~8 lines each.

---

## File Map

**Create:**
- `src/Exceptions/FacebookGraphException.php` — base Graph error.
- `src/Exceptions/FacebookTransientException.php` — retryable.
- `src/Exceptions/FacebookTokenInvalidException.php` — terminal (token dead).
- `src/Enums/FacebookConnectionStatusEnum.php` — `Connected | NeedsReauth | Disabled`.
- `database/migrations/0023_add_token_health_to_facebook_connections_table.php`
- `database/migrations/0024_add_external_id_to_leads_table.php`
- `src/Events/FacebookTokenRefreshed.php`
- `src/Events/FacebookTokenExpiringSoon.php`
- `src/Events/FacebookTokenRefreshFailed.php`
- `src/Events/FacebookConnectionNeedsReauth.php`
- `src/Events/FacebookConnectionReconnected.php`
- `src/Events/FacebookRefreshHealthCheckFailed.php`
- `src/Jobs/RefreshFacebookConnection.php` — per-connection refresh.
- `src/Commands/RefreshFacebookTokensCommand.php` — scan + warn + health.
- Tests: `tests/Feature/FacebookGraphServiceErrorTest.php`, `tests/Feature/RefreshFacebookConnectionTest.php`, `tests/Feature/RefreshFacebookTokensCommandTest.php`, `tests/Feature/FacebookConsumptionSelfHealTest.php`, `tests/Feature/WebhookIdempotencyTest.php`, `tests/Feature/LeadSortConcurrencyTest.php`, `tests/Feature/FacebookReconnectActionTest.php`.

**Modify:**
- `src/Services/FacebookGraphService.php` — shared client (timeout/retry), `classifyError`, token redaction, typed throws.
- `src/Models/FacebookConnection.php` — enum cast, new casts, `needsReauth()`, `isInWarningWindow()`, `scopeDueForRefresh()`, fillable.
- `database/factories/FacebookConnectionFactory.php` — enum + `needsReauth()`/`expiringSoon()` states.
- `src/Jobs/SyncFacebookPages.php` — typed exception handling.
- `src/Services/FacebookPageSynchronizer.php` — let token-invalid bubble.
- `src/Jobs/ImportFacebookLeadsJob.php` — typed exceptions + `external_id` idempotency.
- `src/Http/Controllers/WebhookController.php` — idempotency, ACK-on-token-death, atomic sort, NeedsReauth.
- `src/Http/Controllers/FacebookOAuthController.php` — set `acquired_at`, dispatch `FacebookConnectionReconnected`, guard response keys.
- `src/Models/Lead.php` — `external_id` fillable + `nextSortForPhase()`.
- `src/Drivers/MetaDriver.php` — `meta_reconnect` table action + token-health badge helper.
- `src/Filament/Pages/SourceManagement.php` — token-health column.
- `config/lead-pipeline.php` — `facebook.refresh.*`.
- `src/FilamentLeadPipelineServiceProvider.php` — register command, auto-schedule, remove dead `getMigrations()`.
- `/Users/johnwink/Herd/finance-estate/bootstrap/app.php` — swap to the new command (host app).
- `tests/Feature/JobsTest.php` — rewrite for the new command/job.
- `database/factories/...` as needed.

**Delete:**
- `src/Jobs/RefreshFacebookTokens.php` (replaced by command + per-connection job).
- `src/Notifications/FacebookConnectionExpired.php` — package no longer sends it (events only). Document a host listener in README.

---

## Task 1: Typed Graph exceptions

**Files:**
- Create: `src/Exceptions/FacebookGraphException.php`
- Create: `src/Exceptions/FacebookTransientException.php`
- Create: `src/Exceptions/FacebookTokenInvalidException.php`

- [ ] **Step 1: Create the base exception**

`src/Exceptions/FacebookGraphException.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

use RuntimeException;

class FacebookGraphException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $error  The Graph `error` object, if any.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $error = null,
    ) {
        parent::__construct($message);
    }

    public function graphCode(): ?int
    {
        $code = $this->error['code'] ?? null;

        return is_int($code) ? $code : (is_numeric($code) ? (int) $code : null);
    }

    public function graphSubcode(): ?int
    {
        $sub = $this->error['error_subcode'] ?? null;

        return is_int($sub) ? $sub : (is_numeric($sub) ? (int) $sub : null);
    }
}
```

- [ ] **Step 2: Create the transient and terminal subclasses**

`src/Exceptions/FacebookTransientException.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

/**
 * A temporary Graph failure (rate limit, 5xx, network). Safe to retry.
 */
class FacebookTransientException extends FacebookGraphException {}
```

`src/Exceptions/FacebookTokenInvalidException.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

/**
 * The access token is invalid/expired (Graph code 190 / HTTP 401).
 * Recovery requires a fresh OAuth login — do NOT retry blindly.
 */
class FacebookTokenInvalidException extends FacebookGraphException {}
```

- [ ] **Step 3: Verify autoload**

Run: `composer dump-autoload`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/Exceptions
git commit -m "feat(facebook): add typed Graph exceptions (transient vs token-invalid)"
```

---

## Task 2: Harden `FacebookGraphService` (shared client, classifyError, redaction)

**Files:**
- Modify: `src/Services/FacebookGraphService.php`
- Test: `tests/Feature/FacebookGraphServiceErrorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/FacebookGraphServiceErrorTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

it('throws a token-invalid exception on Graph code 190', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'error' => ['message' => 'Session expired', 'code' => 190, 'type' => 'OAuthException'],
        ], 400),
    ]);

    app(FacebookGraphService::class)->getMe('dead-token');
})->throws(FacebookTokenInvalidException::class);

it('throws a token-invalid exception on HTTP 401', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    app(FacebookGraphService::class)->getMe('dead-token');
})->throws(FacebookTokenInvalidException::class);

it('throws a transient exception on HTTP 429', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'error' => ['message' => 'Rate limit', 'code' => 4],
        ], 429),
    ]);

    app(FacebookGraphService::class)->getMe('token');
})->throws(FacebookTransientException::class);

it('never leaks the access token in the exception message', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'error' => ['message' => 'failed for access_token=SUPERSECRET123'],
        ], 400),
    ]);

    try {
        app(FacebookGraphService::class)->getMe('SUPERSECRET123');
        $this->fail('Expected exception');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('SUPERSECRET123');
    }
});
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/pest tests/Feature/FacebookGraphServiceErrorTest.php --parallel`
Expected: FAIL (current code throws `RuntimeException`, not the typed exceptions; no redaction).

- [ ] **Step 3: Add the shared client, classifier, redaction and refactor calls**

In `src/Services/FacebookGraphService.php` add these imports at the top:

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookGraphException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use Illuminate\Http\Client\Response;
```

Add these methods to the class (keep `$graphVersion`, `$graphUrl`, constructor, `getRedirectUri`, `getOAuthRedirectUrl` as-is):

```php
private function client(): PendingRequest
{
    return Http::timeout(10)
        ->connectTimeout(5)
        ->retry(2, 200, throw: false);
}

/**
 * Classify a failed Graph response into a typed exception.
 *
 * USER-CONTRIBUTION (domain policy): the exact Graph error-code mapping
 * lives here. Defaults below are sensible; refine per Facebook's docs.
 *
 * Terminal  → token is dead, requires re-auth (HTTP 401, Graph code 190).
 * Transient → retryable (HTTP 429/5xx, Graph codes 4/17/32/613).
 */
private function classifyError(Response $response, string $context): FacebookGraphException
{
    /** @var array<string, mixed> $error */
    $error  = (array) $response->json('error', []);
    $status = $response->status();
    $code   = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;

    $message = $this->sanitize($context . ': ' . ($error['message'] ?? $response->body()));

    // ── USER-CONTRIBUTION START ───────────────────────────────────────
    if (401 === $status || 190 === $code) {
        return new FacebookTokenInvalidException($message, $status, $error);
    }

    if (429 === $status || $status >= 500 || in_array($code, [4, 17, 32, 613], true)) {
        return new FacebookTransientException($message, $status, $error);
    }
    // ── USER-CONTRIBUTION END ─────────────────────────────────────────

    return new FacebookGraphException($message, $status, $error);
}

/**
 * Redact token-like material so secrets never reach logs or exceptions.
 */
private function sanitize(string $body): string
{
    return (string) preg_replace(
        '/(access_token|appsecret_proof|client_secret|fb_exchange_token)=[^&\s"\']+/i',
        '$1=[REDACTED]',
        $body,
    );
}
```

Now refactor **every** Graph call to use `$this->client()` and throw via `classifyError`. Pattern — replace each method body's `Http::get(...)`/`Http::post(...)` + `if ($response->failed()) { throw new RuntimeException(...); }` with the shared client and classifier. Example for `exchangeForLongLivedToken` (apply the same pattern to `exchangeCodeForToken`, `getUserPages`, `getPageLeadForms`, `subscribePageToLeadgen`, `getPageSubscribedApps`, `getLeadData`, `getFormQuestions`, `getFormLeads`, `getMe`):

```php
public function exchangeForLongLivedToken(string $shortLivedToken): array
{
    $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/oauth/access_token", [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => config('lead-pipeline.facebook.client_id'),
        'client_secret'     => config('lead-pipeline.facebook.client_secret'),
        'fb_exchange_token' => $shortLivedToken,
    ]);

    if ($response->failed()) {
        throw $this->classifyError($response, 'Facebook long-lived token exchange failed');
    }

    return $response->json();
}
```

For `getMe`:

```php
public function getMe(string $accessToken): array
{
    $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/me", [
        'access_token' => $accessToken,
        'fields'       => 'id,name',
    ]);

    if ($response->failed()) {
        throw $this->classifyError($response, 'Failed to fetch Facebook user');
    }

    return $response->json();
}
```

Remove the now-unused `use RuntimeException;` import.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/FacebookGraphServiceErrorTest.php --parallel`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the existing Graph-dependent suites for regressions**

Run: `./vendor/bin/pest tests/Feature/FacebookPageSynchronizerTest.php tests/Feature/WebhookMetaAttributionTest.php --parallel`
Expected: PASS (existing `Http::fake` responses are 200, so behaviour is unchanged).

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add src/Services/FacebookGraphService.php tests/Feature/FacebookGraphServiceErrorTest.php
git commit -m "feat(facebook): classify Graph errors, add HTTP timeouts/retries, redact tokens"
```

---

## Task 3: `FacebookConnectionStatusEnum`

**Files:**
- Create: `src/Enums/FacebookConnectionStatusEnum.php`

- [ ] **Step 1: Create the enum** (mirrors the `HasLabel/HasColor/HasIcon` style of `LeadSourceStatusEnum`)

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FacebookConnectionStatusEnum: string implements HasColor, HasIcon, HasLabel
{
    case Connected   = 'connected';
    case NeedsReauth = 'needs_reauth';
    case Disabled    = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Connected   => __('lead-pipeline::lead-pipeline.facebook.status.connected'),
            self::NeedsReauth => __('lead-pipeline::lead-pipeline.facebook.status.needs_reauth'),
            self::Disabled    => __('lead-pipeline::lead-pipeline.facebook.status.disabled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Connected   => 'success',
            self::NeedsReauth => 'danger',
            self::Disabled    => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Connected   => 'heroicon-o-check-circle',
            self::NeedsReauth => 'heroicon-o-exclamation-triangle',
            self::Disabled    => 'heroicon-o-no-symbol',
        };
    }
}
```

- [ ] **Step 2: Add the three translation keys** to `resources/lang/de/lead-pipeline.php` (and any other locale present) under the existing `facebook` array, e.g.:

```php
'status' => [
    'connected'    => 'Verbunden',
    'needs_reauth' => 'Neu-Login erforderlich',
    'disabled'     => 'Deaktiviert',
],
```

(Find the `facebook` => [ ... ] block; add the `status` sub-array. Mirror in `en` if present.)

- [ ] **Step 3: Commit**

```bash
git add src/Enums/FacebookConnectionStatusEnum.php resources/lang
git commit -m "feat(facebook): add FacebookConnectionStatusEnum"
```

---

## Task 4: Migration `0023` — token-health columns + status data migration

**Files:**
- Create: `database/migrations/0023_add_token_health_to_facebook_connections_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('facebook_connections', function (Blueprint $table): void {
            $table->timestamp('last_refreshed_at')->nullable()->after('token_expires_at');
            $table->timestamp('acquired_at')->nullable()->after('last_refreshed_at');
            $table->unsignedTinyInteger('refresh_attempts')->default(0)->after('acquired_at');
            $table->timestamp('refresh_failed_at')->nullable()->after('refresh_attempts');
            $table->text('last_error')->nullable()->after('refresh_failed_at');
            $table->timestamp('expiring_soon_notified_at')->nullable()->after('last_error');
        });

        // Migrate legacy status value 'expired' → 'needs_reauth'.
        FacebookConnection::query()
            ->where('status', 'expired')
            ->update(['status' => 'needs_reauth']);
    }

    public function down(): void
    {
        Schema::table('facebook_connections', function (Blueprint $table): void {
            $table->dropColumn([
                'last_refreshed_at',
                'acquired_at',
                'refresh_attempts',
                'refresh_failed_at',
                'last_error',
                'expiring_soon_notified_at',
            ]);
        });
    }
};
```

- [ ] **Step 2: Verify it migrates cleanly in the test DB**

Run: `./vendor/bin/pest tests/Feature/JobsTest.php --parallel`
Expected: still runs (columns now exist; assertions may need Task 5 — that's fine, this step just proves the migration applies without SQL errors).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/0023_add_token_health_to_facebook_connections_table.php
git commit -m "feat(facebook): migration for token-health columns + status backfill"
```

---

## Task 5: `FacebookConnection` model + factory

**Files:**
- Modify: `src/Models/FacebookConnection.php`
- Modify: `database/factories/FacebookConnectionFactory.php`
- Test: `tests/Feature/RefreshFacebookConnectionTest.php` (created in Task 7; the model is exercised there)

- [ ] **Step 1: Update the model**

Replace `$fillable`, `isConnected`/`isExpired`, and `casts()`; add the scope and helpers. Add imports:

```php
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
```

`$fillable` — add the new columns:

```php
protected $fillable = [
    'user_uuid',
    'team_uuid',
    'facebook_user_id',
    'facebook_user_name',
    'access_token',
    'token_expires_at',
    'last_refreshed_at',
    'acquired_at',
    'refresh_attempts',
    'refresh_failed_at',
    'last_error',
    'expiring_soon_notified_at',
    'scopes',
    'status',
];
```

Replace `isConnected()`/`isExpired()` with:

```php
public function isConnected(): bool
{
    return FacebookConnectionStatusEnum::Connected === $this->status;
}

public function needsReauth(): bool
{
    return FacebookConnectionStatusEnum::NeedsReauth === $this->status;
}

public function isInWarningWindow(CarbonInterface $threshold): bool
{
    return null !== $this->token_expires_at && $this->token_expires_at->lessThanOrEqualTo($threshold);
}

/**
 * Connections the refresher should act on: connected AND either inside the
 * warning window, previously failed transiently, or with unknown expiry.
 */
public function scopeDueForRefresh(Builder $query, CarbonInterface $threshold): Builder
{
    return $query
        ->where('status', FacebookConnectionStatusEnum::Connected)
        ->where(function (Builder $q) use ($threshold): void {
            $q->where('token_expires_at', '<=', $threshold)
                ->orWhereNotNull('refresh_failed_at')
                ->orWhereNull('token_expires_at');
        });
}
```

Update `casts()`:

```php
protected function casts(): array
{
    return [
        'access_token'              => 'encrypted',
        'token_expires_at'          => 'datetime',
        'last_refreshed_at'         => 'datetime',
        'acquired_at'               => 'datetime',
        'refresh_failed_at'         => 'datetime',
        'expiring_soon_notified_at' => 'datetime',
        'refresh_attempts'          => 'integer',
        'scopes'                    => 'array',
        'status'                    => FacebookConnectionStatusEnum::class,
    ];
}
```

- [ ] **Step 2: Update the factory**

`database/factories/FacebookConnectionFactory.php` — use the enum and add states:

```php
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;

// definition(): replace 'status' => 'connected' with:
'status' => FacebookConnectionStatusEnum::Connected,

// replace expired() and add states:
public function needsReauth(): static
{
    return $this->state([
        'status'           => FacebookConnectionStatusEnum::NeedsReauth,
        'token_expires_at' => now()->subDay(),
    ]);
}

public function expiringSoon(): static
{
    return $this->state(['token_expires_at' => now()->addDays(3)]);
}
```

- [ ] **Step 3: Update existing tests that use string statuses**

In `tests/Feature/JobsTest.php`, the `SyncFacebookPages` "expired" connection creates `'status' => 'expired'` — change to `'status' => 'needs_reauth'`. (The whole file is rewritten in Task 9; if doing this task first, just make the strings valid enum values so casts don't throw.)

- [ ] **Step 4: Run model-touching suites**

Run: `./vendor/bin/pest tests/Feature/FacebookPageSynchronizerTest.php tests/Feature/FacebookReactivateWebhookActionTest.php --parallel`
Expected: PASS (these create connections with `'status' => 'connected'`, accepted by the enum cast).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add src/Models/FacebookConnection.php database/factories/FacebookConnectionFactory.php tests/Feature/JobsTest.php
git commit -m "feat(facebook): status enum, token-health fields, dueForRefresh scope"
```

---

## Task 6: Domain events

**Files:**
- Create: `src/Events/FacebookTokenRefreshed.php`, `FacebookTokenExpiringSoon.php`, `FacebookTokenRefreshFailed.php`, `FacebookConnectionNeedsReauth.php`, `FacebookConnectionReconnected.php`, `FacebookRefreshHealthCheckFailed.php`

- [ ] **Step 1: Create the events** (mirror the existing `src/Events/LeadCreated.php` convention — `Dispatchable`, `SerializesModels`)

`src/Events/FacebookTokenRefreshed.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookTokenRefreshed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public FacebookConnection $connection) {}
}
```

`src/Events/FacebookTokenExpiringSoon.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookTokenExpiringSoon
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public FacebookConnection $connection,
        public int $daysLeft,
    ) {}
}
```

`src/Events/FacebookTokenRefreshFailed.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookTokenRefreshFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public FacebookConnection $connection,
        public int $attempt,
    ) {}
}
```

`src/Events/FacebookConnectionNeedsReauth.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookConnectionNeedsReauth
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public FacebookConnection $connection,
        public string $reason,
    ) {}
}
```

`src/Events/FacebookConnectionReconnected.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookConnectionReconnected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public FacebookConnection $connection) {}
}
```

`src/Events/FacebookRefreshHealthCheckFailed.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookRefreshHealthCheckFailed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  \Illuminate\Support\Collection<int, FacebookConnection>  $stuckConnections
     */
    public function __construct(
        public \Illuminate\Support\Collection $stuckConnections,
        public int $thresholdHours,
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Events
git commit -m "feat(facebook): add token-lifecycle domain events"
```

---

## Task 7: `RefreshFacebookConnection` job (per-connection self-healing)

**Files:**
- Create: `src/Jobs/RefreshFacebookConnection.php`
- Test: `tests/Feature/RefreshFacebookConnectionTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/RefreshFacebookConnectionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    $this->connection = FacebookConnection::factory()->expiringSoon()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);
});

function runRefresh(FacebookConnection $connection): void
{
    (new RefreshFacebookConnection($connection))->handle(
        app(FacebookGraphService::class),
        app(FacebookPageSynchronizer::class),
    );
}

it('refreshes the token and resets failure state on success', function (): void {
    Event::fake([FacebookTokenRefreshed::class]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'access_token' => 'fresh-token',
            'expires_in'   => 5_184_000,
        ]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
    ]);

    $this->connection->update(['refresh_attempts' => 2, 'refresh_failed_at' => now()->subDay()]);

    runRefresh($this->connection->fresh());

    $fresh = $this->connection->fresh();
    expect($fresh->access_token)->toBe('fresh-token')
        ->and($fresh->refresh_attempts)->toBe(0)
        ->and($fresh->refresh_failed_at)->toBeNull()
        ->and($fresh->last_refreshed_at)->not->toBeNull()
        ->and($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected);

    Event::assertDispatched(FacebookTokenRefreshed::class);
});

it('records a transient failure without marking needs-reauth', function (): void {
    Event::fake([FacebookTokenRefreshFailed::class, FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 4, 'message' => 'rate']], 429),
    ]);

    runRefresh($this->connection->fresh());

    $fresh = $this->connection->fresh();
    expect($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected)
        ->and($fresh->refresh_attempts)->toBe(1)
        ->and($fresh->refresh_failed_at)->not->toBeNull();

    Event::assertDispatched(FacebookTokenRefreshFailed::class);
    Event::assertNotDispatched(FacebookConnectionNeedsReauth::class);
});

it('marks needs-reauth and errors lead sources on a terminal 190', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-x',
        'page_name'                => 'Page X',
        'page_access_token'        => 'pt',
    ]);
    $source = LeadSource::factory()->meta()->active()->create(['facebook_page_uuid' => $page->uuid]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'error' => ['code' => 190, 'message' => 'expired'],
        ], 400),
    ]);

    runRefresh($this->connection->fresh());

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth)
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Error);

    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});

it('escalates to needs-reauth after max attempts when the token is already expired', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    config()->set('lead-pipeline.facebook.refresh.max_attempts', 5);
    $this->connection->update([
        'token_expires_at' => now()->subDay(),
        'refresh_attempts' => 4,
        'refresh_failed_at' => now()->subHours(10),
    ]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 2, 'message' => 'temp']], 500),
    ]);

    runRefresh($this->connection->fresh());

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});

it('skips while inside the backoff window', function (): void {
    Http::fake(); // any call would be unexpected

    $this->connection->update(['refresh_attempts' => 1, 'refresh_failed_at' => now()]);

    runRefresh($this->connection->fresh());

    Http::assertNothingSent();
});

it('treats a malformed refresh response as a transient failure', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['unexpected' => true]),
    ]);

    runRefresh($this->connection->fresh());

    $fresh = $this->connection->fresh();
    expect($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected)
        ->and($fresh->refresh_attempts)->toBe(1)
        ->and($fresh->access_token)->not->toBe('');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/RefreshFacebookConnectionTest.php --parallel`
Expected: FAIL (class does not exist).

- [ ] **Step 3: Implement the job**

`src/Jobs/RefreshFacebookConnection.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

class RefreshFacebookConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public FacebookConnection $connection) {}

    public function handle(FacebookGraphService $facebook, FacebookPageSynchronizer $synchronizer): void
    {
        $connection = $this->connection->fresh();

        if (null === $connection || ! $connection->isConnected()) {
            return;
        }

        if ($this->isInBackoff($connection)) {
            return;
        }

        try {
            $result = $facebook->refreshLongLivedToken($connection->access_token);
        } catch (FacebookTokenInvalidException $e) {
            $this->markNeedsReauth($connection, $e->getMessage());

            return;
        } catch (FacebookTransientException $e) {
            $this->recordTransientFailure($connection, $e->getMessage());

            return;
        }

        $token     = $result['access_token'] ?? null;
        $expiresIn = $result['expires_in'] ?? null;

        if ( ! is_string($token) || '' === $token || ! is_int($expiresIn) || $expiresIn <= 0) {
            $this->recordTransientFailure($connection, 'Malformed token refresh response from Facebook.');

            return;
        }

        $connection->forceFill([
            'access_token'              => $token,
            'token_expires_at'          => now()->addSeconds($expiresIn),
            'last_refreshed_at'         => now(),
            'refresh_attempts'          => 0,
            'refresh_failed_at'         => null,
            'last_error'                => null,
            'expiring_soon_notified_at' => null,
        ])->save();

        try {
            $synchronizer->sync($connection);
        } catch (FacebookTokenInvalidException $e) {
            $this->markNeedsReauth($connection, $e->getMessage());

            return;
        } catch (Throwable) {
            // Page sync is non-fatal to token health; the token itself is valid.
        }

        FacebookTokenRefreshed::dispatch($connection);
    }

    private function isInBackoff(FacebookConnection $connection): bool
    {
        if (null === $connection->refresh_failed_at) {
            return false;
        }

        return now()->lessThan(
            $connection->refresh_failed_at->copy()->addSeconds($this->backoffSeconds($connection->refresh_attempts)),
        );
    }

    private function recordTransientFailure(FacebookConnection $connection, string $reason): void
    {
        $attempts = $connection->refresh_attempts + 1;

        $connection->forceFill([
            'refresh_attempts'  => $attempts,
            'refresh_failed_at' => now(),
            'last_error'        => Str::limit($reason, 1000),
        ])->save();

        if ($this->shouldEscalate($connection)) {
            $this->markNeedsReauth($connection, 'Escalated to re-auth after repeated transient failures.');

            return;
        }

        FacebookTokenRefreshFailed::dispatch($connection, $attempts);
    }

    private function markNeedsReauth(FacebookConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($reason, 1000),
        ])->save();

        $connection->pages()
            ->whereHas('leadSources')
            ->each(function (FacebookPage $page): void {
                $page->leadSources()->update([
                    'status'        => LeadSourceStatusEnum::Error,
                    'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
                ]);
            });

        FacebookConnectionNeedsReauth::dispatch($connection, $reason);
    }

    /**
     * USER-CONTRIBUTION (domain policy): when does a transiently-failing
     * connection become terminal? Default: max attempts reached AND the token
     * is already past its expiry (no point retrying a dead token forever).
     */
    private function shouldEscalate(FacebookConnection $connection): bool
    {
        $maxAttempts = (int) config('lead-pipeline.facebook.refresh.max_attempts', 5);

        return $connection->refresh_attempts >= $maxAttempts
            && null !== $connection->token_expires_at
            && $connection->token_expires_at->isPast();
    }

    /**
     * USER-CONTRIBUTION (domain policy): exponential backoff schedule.
     */
    private function backoffSeconds(int $attempts): int
    {
        $base = (int) config('lead-pipeline.facebook.refresh.backoff_base', 300);
        $max  = (int) config('lead-pipeline.facebook.refresh.backoff_max', 21600);

        $exponent = max(0, $attempts - 1);

        return (int) min($max, $base * (2 ** $exponent));
    }
}
```

> Note: `FacebookPage::pages()` (`$connection->pages()`) and `FacebookPage::leadSources()` already exist (used by the old job). Confirm `FacebookPage` has a `leadSources()` HasMany relation; it does (referenced in the deleted `RefreshFacebookTokens`).

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Feature/RefreshFacebookConnectionTest.php --parallel`
Expected: PASS (6 tests).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add src/Jobs/RefreshFacebookConnection.php tests/Feature/RefreshFacebookConnectionTest.php
git commit -m "feat(facebook): self-healing per-connection token refresh job"
```

---

## Task 8: Config keys for refresh

**Files:**
- Modify: `config/lead-pipeline.php`

- [ ] **Step 1: Add the `refresh` block** inside the existing `'facebook' => [ ... ]` array (after `'scopes'`):

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add config/lead-pipeline.php
git commit -m "feat(facebook): refresh/backoff/health config"
```

---

## Task 9: `RefreshFacebookTokensCommand` + delete old job/notification

**Files:**
- Create: `src/Commands/RefreshFacebookTokensCommand.php`
- Delete: `src/Jobs/RefreshFacebookTokens.php`
- Delete: `src/Notifications/FacebookConnectionExpired.php`
- Modify: `src/FilamentLeadPipelineServiceProvider.php` (register command; remove dead `getMigrations()`; auto-schedule)
- Rewrite: `tests/Feature/JobsTest.php`
- Test: `tests/Feature/RefreshFacebookTokensCommandTest.php`

- [ ] **Step 1: Write the command test**

`tests/Feature/RefreshFacebookTokensCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookRefreshHealthCheckFailed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
});

it('refreshes due connections and skips far-future ones', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    $soon = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
        'token_expires_at' => now()->addDays(3),
    ]);
    $far = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
        'token_expires_at' => now()->addDays(40), 'access_token' => 'far',
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();

    expect($soon->fresh()->access_token)->toBe('new')
        ->and($far->fresh()->access_token)->toBe('far');
});

it('fires the expiring-soon event once per window', function (): void {
    Event::fake([FacebookTokenExpiringSoon::class]);
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    $conn = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
        'token_expires_at' => now()->addDays(5),
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();
    expect($conn->fresh()->expiring_soon_notified_at)->not->toBeNull();

    Event::assertDispatchedTimes(FacebookTokenExpiringSoon::class, 1);

    // Second run must not re-fire.
    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();
    Event::assertDispatchedTimes(FacebookTokenExpiringSoon::class, 1);
});

it('emits a health-check-failed event when a connected token is long past expiry', function (): void {
    Event::fake([FacebookRefreshHealthCheckFailed::class]);
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    // Connected but expired 10h ago and stuck (no recent refresh) → health alarm.
    FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
        'status' => FacebookConnectionStatusEnum::Connected,
        'token_expires_at' => now()->subHours(10),
        'last_refreshed_at' => now()->subDays(5),
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();

    Event::assertDispatched(FacebookRefreshHealthCheckFailed::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/RefreshFacebookTokensCommandTest.php --parallel`
Expected: FAIL (command not registered).

- [ ] **Step 3: Implement the command**

`src/Commands/RefreshFacebookTokensCommand.php`:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookRefreshHealthCheckFailed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class RefreshFacebookTokensCommand extends Command
{
    protected $signature = 'lead-pipeline:facebook:refresh-tokens {--queue : Offload each connection refresh to the queue}';

    protected $description = 'Refresh Facebook long-lived tokens, warn about expiring ones, and report refresh health.';

    public function handle(): int
    {
        $this->reportHealth();

        $warningDays = (int) config('lead-pipeline.facebook.refresh.warning_days', 7);
        $threshold   = now()->addDays($warningDays);

        $this->warnExpiringSoon($threshold);

        $useQueue = (bool) ($this->option('queue') || config('lead-pipeline.facebook.refresh.queue', false));

        FacebookConnection::query()
            ->dueForRefresh($threshold)
            ->get()
            ->each(function (FacebookConnection $connection) use ($useQueue): void {
                $useQueue
                    ? RefreshFacebookConnection::dispatch($connection)
                    : RefreshFacebookConnection::dispatchSync($connection);
            });

        return self::SUCCESS;
    }

    private function warnExpiringSoon(\Carbon\CarbonInterface $threshold): void
    {
        FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $threshold)
            ->whereNull('expiring_soon_notified_at')
            ->get()
            ->each(function (FacebookConnection $connection): void {
                $daysLeft = max(0, (int) now()->diffInDays($connection->token_expires_at, false));
                $connection->forceFill(['expiring_soon_notified_at' => now()])->save();
                FacebookTokenExpiringSoon::dispatch($connection, $daysLeft);
            });
    }

    private function reportHealth(): void
    {
        $hours = (int) config('lead-pipeline.facebook.refresh.health_hours', 3);

        $stuck = FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now()->subHours($hours))
            ->get();

        if ($stuck->isNotEmpty()) {
            FacebookRefreshHealthCheckFailed::dispatch($stuck, $hours);
        }
    }
}
```

> Health semantics: a *connected* connection whose token expired more than N hours ago means the refresher isn't running (queue/scheduler problem) — exactly the production failure mode. `reportHealth()` runs **before** the refresh so it reflects the prior period.

- [ ] **Step 4: Delete old job + notification, register command, remove dead `getMigrations()`, auto-schedule**

Delete the files:

```bash
git rm src/Jobs/RefreshFacebookTokens.php src/Notifications/FacebookConnectionExpired.php
```

In `src/FilamentLeadPipelineServiceProvider.php`:

1. Add to `->hasCommands([...])`: `Commands\RefreshFacebookTokensCommand::class,`.
2. Delete the entire `getMigrations()` method (dead code — Spatie never calls it here; migrations load via `loadMigrationsFrom`).
3. Auto-schedule. Add imports:

```php
use Illuminate\Console\Scheduling\Schedule;
use JohnWink\FilamentLeadPipeline\Commands\RefreshFacebookTokensCommand;
```

Add to `boot()` (after `parent::boot();`):

```php
if (config('lead-pipeline.facebook.refresh.enabled', true)) {
    $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
        $event = $schedule->command('lead-pipeline:facebook:refresh-tokens')
            ->withoutOverlapping()
            ->onOneServer();

        $cadence = (string) config('lead-pipeline.facebook.refresh.cadence', 'hourly');
        method_exists($event, $cadence) ? $event->{$cadence}() : $event->hourly();
    });
}
```

- [ ] **Step 5: Rewrite `tests/Feature/JobsTest.php`** (it referenced the deleted `RefreshFacebookTokens`). Keep the `SyncFacebookPages` tests; remove the `RefreshFacebookTokens` tests (now covered by `RefreshFacebookConnectionTest` + `RefreshFacebookTokensCommandTest`). New file:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\SyncFacebookPages;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    $this->connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);
});

it('syncs pages for all connected Facebook connections', function (): void {
    $synchronizer = Mockery::mock(FacebookPageSynchronizer::class);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn ($c): bool => $c->uuid === $this->connection->uuid))
        ->andReturn(['added' => 1, 'updated' => 0, 'removed' => 0, 'forms_synced' => 0]);
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect(true)->toBeTrue();
});

it('skips needs-reauth connections and continues despite sync failures', function (): void {
    FacebookConnection::factory()->needsReauth()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);

    $synchronizer = Mockery::mock(FacebookPageSynchronizer::class);
    $synchronizer->shouldReceive('sync')->once()->andThrow(new RuntimeException('boom'));
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect(true)->toBeTrue();
});
```

> `SyncFacebookPages` still queries `where('status', 'connected')`. Update it in Task 10 to use the enum; for now this test passes because the enum cast stores `'connected'`.

- [ ] **Step 6: Run the command + jobs suites**

Run: `./vendor/bin/pest tests/Feature/RefreshFacebookTokensCommandTest.php tests/Feature/JobsTest.php --parallel`
Expected: PASS.

- [ ] **Step 7: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(facebook): scheduled refresh command, auto-schedule, drop legacy job/notification"
```

---

## Task 10: Self-heal `SyncFacebookPages` + `FacebookPageSynchronizer`

**Files:**
- Modify: `src/Services/FacebookPageSynchronizer.php`
- Modify: `src/Jobs/SyncFacebookPages.php`
- Test: `tests/Feature/FacebookConsumptionSelfHealTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/FacebookConsumptionSelfHealTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Jobs\SyncFacebookPages;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
    ]);
});

it('marks the connection needs-reauth when sync hits a dead token', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
    ]);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/FacebookConsumptionSelfHealTest.php --parallel`
Expected: FAIL (current `SyncFacebookPages` swallows everything silently).

- [ ] **Step 3: Update `FacebookPageSynchronizer::sync()`** — let token-invalid bubble. The `getUserPages()` call already throws the typed exception (Task 2). `syncFormsFor()` already swallows form errors (acceptable). No change needed to `sync()` itself beyond confirming it does **not** wrap `getUserPages()` in a try/catch (it doesn't). Leave as-is.

- [ ] **Step 4: Update `SyncFacebookPages::handle()`** to route token death:

```php
<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

class SyncFacebookPages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(FacebookPageSynchronizer $synchronizer): void
    {
        FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->get()
            ->each(function (FacebookConnection $connection) use ($synchronizer): void {
                try {
                    $synchronizer->sync($connection);
                } catch (FacebookTokenInvalidException $e) {
                    $this->markNeedsReauth($connection, $e->getMessage());
                } catch (Throwable $e) {
                    Log::warning('SyncFacebookPages: sync failed', [
                        'connection' => $connection->uuid,
                        'error'      => $e->getMessage(),
                    ]);
                }
            });
    }

    private function markNeedsReauth(FacebookConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($reason, 1000),
        ])->save();

        $connection->pages()
            ->whereHas('leadSources')
            ->each(fn (FacebookPage $page) => $page->leadSources()->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
            ]));

        FacebookConnectionNeedsReauth::dispatch($connection, $reason);
    }
}
```

> The `markNeedsReauth` logic is duplicated between `RefreshFacebookConnection` and `SyncFacebookPages`. If you prefer DRY, extract it to a small `MarksConnectionNeedsReauth` trait under `src/Concerns/`. Optional; keep inline if simpler for review.

- [ ] **Step 5: Run to verify pass + regressions**

Run: `./vendor/bin/pest tests/Feature/FacebookConsumptionSelfHealTest.php tests/Feature/JobsTest.php tests/Feature/FacebookPageSynchronizerTest.php --parallel`
Expected: PASS.

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(facebook): detect token death during page sync and flag needs-reauth"
```

---

## Task 11: Migration `0024` (external_id) + Lead model

**Files:**
- Create: `database/migrations/0024_add_external_id_to_leads_table.php`
- Modify: `src/Models/Lead.php`
- Test: `tests/Feature/LeadSortConcurrencyTest.php`

- [ ] **Step 1: Write the migration** (respects configurable PK; unique per source FK column)

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $sourceFk = 'uuid' === config('lead-pipeline.primary_key_type', 'uuid')
            ? 'lead_source_uuid'
            : 'lead_source_id';

        Schema::table('leads', function (Blueprint $table) use ($sourceFk): void {
            $table->string('external_id')->nullable()->after($sourceFk);
            $table->unique([$sourceFk, 'external_id'], 'leads_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropUnique('leads_source_external_unique');
            $table->dropColumn('external_id');
        });
    }
};
```

- [ ] **Step 2: Update `Lead` model** — add `'external_id'` to `$fillable` (after `'lead_source_id'`), and add the atomic sort helper:

```php
use Illuminate\Support\Facades\DB;

/**
 * Atomically determine the next sort value for a phase, avoiding the
 * read-then-write race that collides under concurrent webhook deliveries.
 */
public static function nextSortForPhase(int|string $phaseKey): int
{
    return (int) DB::transaction(function () use ($phaseKey): int {
        $max = static::query()
            ->where(static::fkColumn('lead_phase'), $phaseKey)
            ->lockForUpdate()
            ->max('sort');

        return (int) ($max ?? 0) + 1;
    });
}
```

> SQLite (tests) ignores `lockForUpdate` but the transaction still works; MySQL (prod) takes the row lock. This is the standard Laravel pattern for this.

- [ ] **Step 3: Write the concurrency test**

`tests/Feature/LeadSortConcurrencyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

it('assigns sequential sort values via nextSortForPhase', function (): void {
    $team  = Team::query()->firstWhere('slug', 'test');
    $board = LeadBoard::factory()->create(['team_uuid' => $team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open, 'sort' => 0,
    ]);

    $first  = Lead::nextSortForPhase($phase->getKey());
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['sort' => $first]);
    $second = Lead::nextSortForPhase($phase->getKey());

    expect($first)->toBe(1)->and($second)->toBe(2);
});
```

- [ ] **Step 4: Run**

Run: `./vendor/bin/pest tests/Feature/LeadSortConcurrencyTest.php --parallel`
Expected: PASS.

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/0024_add_external_id_to_leads_table.php src/Models/Lead.php tests/Feature/LeadSortConcurrencyTest.php
git commit -m "feat(leads): external_id idempotency column + atomic nextSortForPhase"
```

---

## Task 12: `ImportFacebookLeadsJob` — typed exceptions + idempotency

**Files:**
- Modify: `src/Jobs/ImportFacebookLeadsJob.php`

- [ ] **Step 1: Add token-death handling** — replace the `catch (Exception $e)` block around `getFormLeads` (currently matches strings `'rate limit'`/`'429'`) with typed handling. Add imports:

```php
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use Illuminate\Support\Str;
```

Replace the try/catch in the `do { ... }` loop:

```php
try {
    $result = $facebook->getFormLeads($formId, $page->page_access_token, $since, $afterCursor);
} catch (FacebookTransientException) {
    $this->release(60);

    return;
} catch (FacebookTokenInvalidException $e) {
    $connection = $page->connection;
    if ($connection) {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($e->getMessage(), 1000),
        ])->save();
        FacebookConnectionNeedsReauth::dispatch($connection, $e->getMessage());
    }
    $source->update([
        'status'        => LeadSourceStatusEnum::Error,
        'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
    ]);

    return;
} catch (Exception $e) {
    $source->update([
        'status'        => LeadSourceStatusEnum::Error,
        'error_message' => $e->getMessage(),
    ]);

    return;
}
```

> `FacebookPage::connection()` BelongsTo exists (inverse of `FacebookConnection::pages()`); confirm and use it.

- [ ] **Step 2: Set `external_id` on created/updated leads** — in both the "create new lead" and "update existing" branches, set `external_id` to `$fbLeadId`. In the create branch add `$lead->external_id = $fbLeadId;` before `$lead->save();`. In the `$updates` array add `'external_id' => $fbLeadId,`. Also switch the dedup lookup to prefer `external_id`:

```php
$existingLead = null;
if ($fbLeadId) {
    $existingLead = Lead::query()
        ->where(Lead::fkColumn('lead_board'), $board->getKey())
        ->where('external_id', $fbLeadId)
        ->first();
}
if ( ! $existingLead && $email) {
    $existingLead = Lead::query()
        ->where(Lead::fkColumn('lead_board'), $board->getKey())
        ->where('email', $email)
        ->first();
}
```

- [ ] **Step 3: Run the import attribution suite for regressions**

Run: `./vendor/bin/pest tests/Feature/ImportFacebookLeadsAttributionTest.php --parallel`
Expected: PASS (existing fakes are 200; new `external_id` writes are additive).

- [ ] **Step 4: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add src/Jobs/ImportFacebookLeadsJob.php
git commit -m "feat(facebook): import detects token death + dedups via external_id"
```

---

## Task 13: `WebhookController` — idempotency, ACK-on-token-death, atomic sort

**Files:**
- Modify: `src/Http/Controllers/WebhookController.php`
- Test: `tests/Feature/WebhookIdempotencyTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/WebhookIdempotencyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'test-app-secret');

    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Open, 'sort' => 0]);

    $this->connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
    ]);
    $this->fbPage = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id' => 'page-100', 'page_name' => 'Test Page',
        'page_access_token' => 'page-token', 'is_webhooks_subscribed' => true,
    ]);
    $this->source = LeadSource::query()->create([
        'name' => 'Meta Source', 'driver' => 'meta', 'status' => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid' => $this->team->uuid, 'created_by' => $this->user->getKey(),
        'facebook_page_uuid' => $this->fbPage->uuid, 'facebook_form_ids' => ['form-1'],
    ]);
});

function metaCall(array $payload): \Illuminate\Testing\TestResponse
{
    $url     = '/' . config('lead-pipeline.webhooks.prefix') . '/meta';
    $content = json_encode($payload);
    $secret  = config('lead-pipeline.facebook.client_secret');

    return test()->withHeader('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $content, $secret))
        ->postJson($url, $payload);
}

function leadgenPayload(string $leadgenId): array
{
    return ['entry' => [[
        'id' => 'page-100',
        'changes' => [['field' => 'leadgen', 'value' => [
            'leadgen_id' => $leadgenId, 'form_id' => 'form-1', 'page_id' => 'page-100',
        ]]],
    ]]];
}

it('creates only one lead when the same leadgen webhook is delivered twice', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-dup*' => Http::response([
            'id' => 'lead-dup', 'form_id' => 'form-1',
            'field_data' => [['name' => 'email', 'values' => ['dup@example.com']]],
        ]),
    ]);

    metaCall(leadgenPayload('lead-dup'))->assertOk();
    metaCall(leadgenPayload('lead-dup'))->assertOk();

    expect(Lead::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

it('acks with 200 and flags needs-reauth when the page token is dead', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/lead-dead*' => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
    ]);

    metaCall(leadgenPayload('lead-dead'))->assertOk();

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/WebhookIdempotencyTest.php --parallel`
Expected: FAIL (duplicate lead created; token-death currently 500s).

- [ ] **Step 3: Update `handleMetaCentral`** — wrap `getLeadData`, set `external_id`, dedup, atomic sort. Add imports:

```php
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use Illuminate\Support\Str;
```

Replace the `$facebook->getLeadData(...)` line (currently outside any try) with a guarded fetch:

```php
$facebook = app(FacebookGraphService::class);

try {
    $leadData = $facebook->getLeadData($leadgenId, $fbPage->page_access_token);
} catch (FacebookTokenInvalidException $e) {
    $connection = $fbPage->connection;
    if ($connection) {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($e->getMessage(), 1000),
        ])->save();
        FacebookConnectionNeedsReauth::dispatch($connection, $e->getMessage());
    }

    // ACK so Facebook stops retrying (prevents the 36h-then-disable cascade);
    // missed leads are backfilled via ImportFacebookLeadsJob after reconnect.
    continue;
}
```

In `createLeadFromFacebookData`, add idempotency + atomic sort. At the start (after `$board = $source->board;`):

```php
$externalId = $rawData['id'] ?? null;

if ($externalId) {
    $existing = Lead::query()
        ->where(Lead::fkColumn('lead_board'), $board->getKey())
        ->where('external_id', $externalId)
        ->first();

    if ($existing) {
        return $existing;
    }
}
```

Replace the `$maxSort = ...; $lead->sort = $maxSort + 1;` lines with:

```php
$lead->sort = Lead::nextSortForPhase($targetPhase->getKey());
```

and add `$lead->external_id = $externalId;` before `$lead->save();`.

> Note: `createLeadFromFacebookData($source, $fieldData, $leadData)` is called with `$rawData = $leadData` (the Graph lead payload whose `id` is the leadgen id). Confirm the `$rawData['id']` is the leadgen id — it is (`getLeadData` returns `id => leadgen id`).

- [ ] **Step 4: Apply the same atomic sort to `handle()`** — replace its `$maxSort`/`$lead->sort = $maxSort + 1;` with `$lead->sort = Lead::nextSortForPhase($targetPhase->getKey());`.

- [ ] **Step 5: Run to verify pass + regressions**

Run: `./vendor/bin/pest tests/Feature/WebhookIdempotencyTest.php tests/Feature/WebhookMetaAttributionTest.php tests/Feature/WebhookHandleTest.php --parallel`
Expected: PASS.

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add src/Http/Controllers/WebhookController.php tests/Feature/WebhookIdempotencyTest.php
git commit -m "feat(webhook): idempotent leads, ACK + needs-reauth on dead token, atomic sort"
```

---

## Task 14: OAuth callback — acquired_at, reconnected event, response guards

**Files:**
- Modify: `src/Http/Controllers/FacebookOAuthController.php`

- [ ] **Step 1: Guard the exchange responses + set bookkeeping + dispatch event.** Add imports:

```php
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionReconnected;
```

After `$code = $request->query('code');` guard the exchanges:

```php
$shortLived = $this->facebook->exchangeCodeForToken($code);
$longLived  = $this->facebook->exchangeForLongLivedToken($shortLived['access_token'] ?? '');
$me         = $this->facebook->getMe($longLived['access_token'] ?? '');

if (empty($longLived['access_token']) || empty($me['id'])) {
    return response('Facebook returned an incomplete response.', 502);
}

$expiresIn = is_int($longLived['expires_in'] ?? null) && $longLived['expires_in'] > 0
    ? $longLived['expires_in']
    : 5184000;
```

Update the `updateOrCreate` values to use the enum, `acquired_at`, the validated `$expiresIn`, and reset health fields:

```php
[
    'team_uuid'                 => $teamId,
    'facebook_user_name'        => $me['name'] ?? null,
    'access_token'              => $longLived['access_token'],
    'token_expires_at'          => now()->addSeconds($expiresIn),
    'acquired_at'               => now(),
    'last_refreshed_at'         => now(),
    'refresh_attempts'          => 0,
    'refresh_failed_at'         => null,
    'last_error'                => null,
    'expiring_soon_notified_at' => null,
    'scopes'                    => config('lead-pipeline.facebook.scopes'),
    'status'                    => FacebookConnectionStatusEnum::Connected,
],
```

After `$this->synchronizer->sync($connection);` dispatch the reconnected event:

```php
FacebookConnectionReconnected::dispatch($connection);
```

- [ ] **Step 2: Run the OAuth callback test for regressions**

Run: `./vendor/bin/pest tests/Feature/FacebookOAuthCallbackTest.php --parallel`
Expected: PASS (the existing fakes return `access_token`, `id`, `expires_in`).

- [ ] **Step 3: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add src/Http/Controllers/FacebookOAuthController.php
git commit -m "feat(facebook): OAuth callback bookkeeping, response guards, reconnected event"
```

---

## Task 15: Token-health badge + one-click reconnect action

**Files:**
- Modify: `src/Drivers/MetaDriver.php` (add the `reconnect` table action)
- Modify: `src/Filament/Pages/SourceManagement.php` (token-health column)
- Test: `tests/Feature/FacebookReconnectActionTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/FacebookReconnectActionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    LeadBoard::created(function (LeadBoard $board): void {
        $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    });
});

function metaSourceWithStatus(Team $team, $user, FacebookConnectionStatusEnum $status): LeadSource
{
    $connection = FacebookConnection::factory()->create([
        'user_uuid' => $user->id, 'team_uuid' => $team->uuid, 'status' => $status,
    ]);
    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id' => 'page-' . uniqid(), 'page_name' => 'P', 'page_access_token' => 'pt',
    ]);
    $board = LeadBoard::factory()->create(['team_uuid' => $team->uuid]);

    return LeadSource::query()->create([
        'name' => 'Meta', 'driver' => 'meta', 'status' => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid' => $team->uuid, 'created_by' => $user->getKey(),
        'facebook_page_uuid' => $page->uuid,
    ]);
}

it('shows the reconnect action when the connection needs reauth', function (): void {
    $source = metaSourceWithStatus($this->team, $this->user, FacebookConnectionStatusEnum::NeedsReauth);

    livewire(SourceManagement::class)
        ->assertTableActionVisible('meta_reconnect', $source);
});

it('hides the reconnect action when the connection is healthy', function (): void {
    $source = metaSourceWithStatus($this->team, $this->user, FacebookConnectionStatusEnum::Connected);

    livewire(SourceManagement::class)
        ->assertTableActionHidden('meta_reconnect', $source);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Feature/FacebookReconnectActionTest.php --parallel`
Expected: FAIL (`meta_reconnect` action does not exist).

- [ ] **Step 3: Add the reconnect action to `MetaDriver::getTableActions()`** — append to the returned array (after `reactivate_webhook`). Add `use Illuminate\Support\Facades\URL;` is not needed; use the named route. The action opens the OAuth redirect in a new tab:

```php
TableAction::make('reconnect')
    ->label(__('lead-pipeline::lead-pipeline.facebook.reconnect'))
    ->icon('heroicon-o-arrow-path-rounded-square')
    ->color('danger')
    ->url(fn (): string => route('lead-pipeline.facebook.redirect'))
    ->openUrlInNewTab()
    ->visible(function (LeadSource $record): bool {
        $connection = $record->facebookPage?->connection;

        return null !== $connection
            && ($connection->needsReauth() || $connection->isInWarningWindow(now()->addDays(
                (int) config('lead-pipeline.facebook.refresh.warning_days', 7),
            )));
    }),
```

> The `SourceManagement::getDriverTableActions()` wrapper overrides `->visible()` with a driver-name check AND prefixes the name to `meta_reconnect`. Since it **replaces** visibility with `fn ($record) => $record->driver === 'meta'`, the per-action visibility above would be lost. To preserve it, the wrapper must AND the two conditions. Update `getDriverTableActions()` in `SourceManagement.php`:

Replace:

```php
// Only show for matching driver
$action->visible(fn (LeadSource $record) => $record->driver === $driverName);
```

with:

```php
// Compose driver-match with the action's own visibility (if any).
$ownVisibility = $action->getVisibility();
$action->visible(fn (LeadSource $record): bool => $record->driver === $driverName
    && (true === $ownVisibility || (is_callable($ownVisibility) ? (bool) $ownVisibility($record) : (bool) $ownVisibility)));
```

> Verify `Filament\Tables\Actions\Action::getVisibility()` exists in this Filament 3 version; if the API differs, evaluate the action's visibility via its public API (e.g. `$action->isVisible($record)` is not record-aware here). If composing is awkward, instead encode the reconnect visibility entirely in the wrapper for the `reconnect` action name. The test (`assertTableActionVisible`/`Hidden`) is the source of truth — make it pass.

- [ ] **Step 4: Add the token-health column to `SourceManagement::table()`** — after the `status` column, add a derived badge (read from the connected page's connection):

```php
Tables\Columns\TextColumn::make('facebook_health')
    ->label(__('lead-pipeline::lead-pipeline.facebook.token_health'))
    ->badge()
    ->state(function (LeadSource $record): ?string {
        if ('meta' !== $record->driver) {
            return null;
        }
        $connection = $record->facebookPage?->connection;
        if (null === $connection) {
            return null;
        }

        return $connection->status->getLabel();
    })
    ->color(fn (LeadSource $record): string => 'meta' === $record->driver
        && $record->facebookPage?->connection
        ? $record->facebookPage->connection->status->getColor()
        : 'gray'),
```

Add the `reconnect` and `token_health` translation keys to `resources/lang/de/lead-pipeline.php` (and `en`):

```php
'reconnect'    => 'Neu verbinden',
'token_health' => 'Token-Status',
```

- [ ] **Step 5: Run to verify pass + regressions**

Run: `./vendor/bin/pest tests/Feature/FacebookReconnectActionTest.php tests/Feature/FacebookReactivateWebhookActionTest.php --parallel`
Expected: PASS.

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(facebook): token-health badge + one-click reconnect action"
```

---

## Task 16: Host-app scheduler swap

**Files:**
- Modify: `/Users/johnwink/Herd/finance-estate/bootstrap/app.php`

- [ ] **Step 1: Replace the legacy job schedule** — change:

```php
$schedule->job(new RefreshFacebookTokens())
    ->dailyAt('03:30')
    ...
```

to:

```php
$schedule->command('lead-pipeline:facebook:refresh-tokens')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

Remove the now-unused `use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookTokens;` import. (Keep `SyncFacebookPages` if still scheduled.)

> The package also auto-registers this schedule (Task 9). Keeping it in the host `bootstrap/app.php` is redundant but harmless with `withoutOverlapping()`. **Decision:** remove the host entry and rely on the package auto-schedule (single source of truth), OR keep only the host entry and set `LEAD_PIPELINE_FB_REFRESH_ENABLED=false` to disable the package auto-schedule. Recommended: rely on the package auto-schedule; delete the host entry. Confirm with the team which they prefer.

- [ ] **Step 2: Verify the host app boots & the schedule lists the command**

Run (host app): `php artisan schedule:list`
Expected: `lead-pipeline:facebook:refresh-tokens` appears once, hourly.

- [ ] **Step 3: Commit (host app repo)**

```bash
git -C /Users/johnwink/Herd/finance-estate add bootstrap/app.php
git -C /Users/johnwink/Herd/finance-estate commit -m "chore(schedule): use lead-pipeline facebook refresh command hourly"
```

---

## Task 17: Full-suite verification + README listener docs

**Files:**
- Modify: `README.md` (document the events + an example host listener)

- [ ] **Step 1: Add a "Facebook token events" section to README** showing how a host app listens (replacing the removed auto-notification):

````markdown
### Facebook token lifecycle events

The package never sends notifications itself — it dispatches events so each app
decides how to alert. Register listeners in your `EventServiceProvider`:

```php
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Events\FacebookRefreshHealthCheckFailed;

protected $listen = [
    FacebookTokenExpiringSoon::class       => [NotifyTokenExpiringSoon::class],
    FacebookConnectionNeedsReauth::class   => [NotifyReconnectRequired::class],
    FacebookRefreshHealthCheckFailed::class => [AlertOpsRefreshStuck::class],
];
```

Other events: `FacebookTokenRefreshed`, `FacebookTokenRefreshFailed`, `FacebookConnectionReconnected`.
````

- [ ] **Step 2: Run the full suite in parallel**

Run: `./vendor/bin/pest --parallel`
Expected: PASS (all green). Investigate and fix any regressions before proceeding.

- [ ] **Step 3: Static analysis + formatting**

Run: `vendor/bin/pint --format agent` then `vendor/bin/phpstan analyse`
Expected: clean (resolve any new findings).

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs(facebook): document token lifecycle events + host listener pattern"
```

---

## Self-Review notes (for the implementer)

- **Spec coverage:** A (Task 3–5), B (Task 1–2), C (Task 7–9), D (Task 6), E (Task 10, 12, 13), F idempotency/ACK (Task 11, 13) — F auth-scheme change deliberately dropped (see header), G (Task 11, 13), H (Task 15), I (Task 8, 9, 16), J (Task 9 removes `getMigrations()`; Task 9 removes notification), K woven throughout.
- **Duplicate `markNeedsReauth`** exists in `RefreshFacebookConnection`, `SyncFacebookPages`, `ImportFacebookLeadsJob`, `WebhookController`. Consider extracting a `Concerns\MarksConnectionNeedsReauth` trait or a small service. Optional but recommended for paragon DRY — do it once Task 13 is green so the refactor is test-covered.
- **Relationship assumptions to confirm before coding:** `FacebookConnection::pages()` (exists), `FacebookPage::leadSources()` (exists — used by old job), `FacebookPage::connection()` (BelongsTo — confirm name). If `connection()` is named differently, adjust.
- **Filament `getVisibility()` API** (Task 15 Step 3) — verify against the installed Filament 3 version; the test is the source of truth.
- **Parallel tests:** SQLite `:memory:` is per-process under `--parallel`; `RefreshDatabase` + `TestSeeder` re-seed per test. No shared-state concerns expected.
