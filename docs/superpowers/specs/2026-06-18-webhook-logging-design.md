# Webhook-Logging für filament-lead-pipeline

**Datum:** 2026-06-18
**Status:** Design (zur Freigabe)
**Ziel-Release:** v0.1.46

## Problem

Der Webhook-Empfang (`WebhookController::handle` / `handleMetaCentral`) und die
Facebook-Registrierung (`FacebookGraphService::subscribePageToLeadgen`) protokollieren
heute praktisch nichts:

- `handle()` loggt **keinen** eingehenden POST. Einzige dauerhafte Spur eines
  *erfolgreichen* Treffers ist `lead_sources.last_received_at`. Die stillen Drops
  (404 inaktive Quelle, 403 Signatur, 422 keine Phase, 500 gefangene Exception)
  hinterlassen **keine** Spur.
- `subscribePageToLeadgen()` verwirft die Facebook-Antwort und gibt nur `bool`
  zurück; nur der OAuth-Aufrufer loggt im Fehlerfall die blanke `getMessage()`.
  Die **vollständige FB-Antwort** (Erfolg wie Fehler) ist nirgends sichtbar.

Folge: Man kann nicht nachvollziehen, ob ein Lead per Webhook ankam, und nicht,
was Facebook beim Registrieren des Webhooks geantwortet hat.

## Ziel

Jedes Webhook-relevante Ereignis nachvollziehbar machen — sowohl in einer
durchsuchbaren DB-Tabelle (in-App via Filament sichtbar) als auch in einem
eigenen Log-Channel. Vier Ereignistypen: `incoming`, `registration`, `verify`,
`status_check`.

## Nicht-Ziele

- Keine Änderung am Empfangs-/Registrierungs-**Verhalten** (Statuscodes,
  Dedup, Subscribe-Logik bleiben unverändert). Reines Hinzufügen von Logging.
- Kein Fix der separaten „Formulare → CRM"-Lead-Access-Sichtbarkeit (eigener
  Strang; das Logging macht die Subscribe-Antwort nur sichtbar).
- Kein Fix der bekannten Latent-Bugs (api_token-Generierung, Error-Status-Kaskade) —
  separat zu behandeln.

## Leitprinzip: Ausfallsicherheit

Logging ist vollständig in `try/catch` gekapselt. Ein Fehler beim Schreiben des
Logs darf **niemals** einen Lead-Empfang, eine Registrierung oder den
Verify-Handshake brechen. Im Zweifel: Ereignis schlucken, App-Pfad läuft weiter.

## Architektur

### 1. Tabelle `lead_webhook_logs` (Migration `0033_create_lead_webhook_logs_table.php`)

Append-only. PK über `HasConfigurablePrimaryKey` (UUIDv7, konsistent mit anderen Tabellen).

| Spalte | Typ | Zweck |
|---|---|---|
| `uuid`/`id` | PK | konfigurierbarer PK |
| `team_uuid` | uuid, nullable, idx | Tenant-Scoping |
| `lead_source_uuid` | uuid, nullable, idx, FK nullOnDelete | bei incoming/registration |
| `facebook_page_uuid` | uuid, nullable | bei registration/verify/status |
| `page_id` | string, nullable | FB Page-ID (auch wenn Page nicht in DB) |
| `lead_uuid` | uuid, nullable | erzeugter Lead bei `created` |
| `event_type` | string (enum), idx | `incoming`/`registration`/`verify`/`status_check` |
| `driver` | string, nullable | `api`/`zapier`/`meta`/`funnel` |
| `outcome` | string, idx | s. u. |
| `http_status` | smallint, nullable | HTTP-Code des Ereignisses |
| `message` | text, nullable | Grund / Fehlermeldung |
| `request` | json, nullable | eingehender Body / ausgehende Request-Params (Token redigiert) |
| `response` | json, nullable | volle FB-/eigene Antwort |
| `created_at` | timestamp, idx | — |

**Outcomes (dokumentierte Strings):** `created`, `rejected_signature` (403),
`source_inactive` (404), `no_phase` (422), `processing_error` (500), `skipped`
(Meta-Form nicht gemappt / Page nicht gefunden / Feld != leadgen / Token ungültig),
`subscribed`, `subscribe_failed`, `verified`, `verify_failed`, `ok` (status_check).

### 2. Enum `WebhookLogEventType`

`enum WebhookLogEventType: string` mit TitleCase-Cases: `Incoming`, `Registration`,
`Verify`, `StatusCheck`. (`outcome` bleibt String mit dokumentierten Konstanten,
um eine Enum-Explosion zu vermeiden.)

### 3. Model `LeadWebhookLog`

`HasConfigurablePrimaryKey` + `BelongsToTeam`-Scope (wie `LeadSource`).
Casts: `request`/`response` → array, `event_type` → `WebhookLogEventType`,
`created_at` → datetime. Relationen: `leadSource()`, `lead()`, `facebookPage()`.
Append-only (keine Updates).

### 4. Service `WebhookLogger`

Eine Klasse, ein Zweck. Typisierte Methoden:

- `recordIncoming(?LeadSource $source, Request $request, string $outcome, int $httpStatus, ?string $message = null, ?Lead $lead = null, ?array $response = null): void`
- `recordRegistration(string $pageId, array $request, ?array $response, bool $success, ?string $message = null): void`
- `recordVerify(?LeadSource $source, string $pageId, bool $ok, ?string $message = null): void`
- `recordStatusCheck(string $pageId, ?array $response, bool $ok, ?string $message = null): void`

Jede Methode: schreibt **DB-Zeile** und emittiert eine **strukturierte Zeile**
in den Log-Channel. Vor dem Speichern **Token-Redaction**: `access_token`,
`page_access_token`, `Authorization`/Bearer, `X-Hub-Signature-256` entfernen.
`recordRegistration` resolved Team/Page via `FacebookPage::where('page_id', …)`.
Respektiert `config('lead-pipeline.webhooks.logging.store_payload')`: ist es
`false`, werden `request`/`response` auf Metadaten reduziert (PII-Kill-Switch).
Gesamter Rumpf in `try/catch` → `report($e)`, nie werfen.

### 5. Log-Channel

Plugin registriert in `FilamentLeadPipelineServiceProvider::boot()` einen
`lead-webhooks`-daily-Channel **nur falls nicht vorhanden** (host kann override).
Logger nutzt `Log::channel(config('lead-pipeline.webhooks.logging.channel', 'lead-webhooks'))`.

### 6. Verdrahtung (Edits, generisch)

- `src/Services/FacebookGraphService.php`
  - `subscribePageToLeadgen()`: rohe `$response` **vor** `classifyError`/throw an
    `recordRegistration()` geben — Erfolg (`subscribed` + Body) **und** Fehler
    (`subscribe_failed` + voller FB-Fehler-Body). Zentral → deckt OAuth-Callback,
    `connect-facebook`-Command und „Webhook reaktivieren"-Action automatisch ab.
  - `getPageSubscribedApps()`: `recordStatusCheck()` mit roher `subscribed_apps`-Antwort.
  - `WebhookLogger` per Constructor-Injection (Container-resolved; manuelle
    `new FacebookGraphService(...)`-Stellen prüfen/anpassen).
- `src/Http/Controllers/WebhookController.php`
  - `handle()`: `recordIncoming()` an jedem Return — 404 (auch ohne Source, mit
    Route-`sourceId` in `request`), 403, 422, 201 (mit Lead), 500.
  - `handleMetaCentral()`: pro Change/Lead `recordIncoming()` mit Outcome
    (`created` / `skipped` + Grund / `rejected_signature`).
  - `verifyMetaCentral()` + `verifyMeta()`: `recordVerify()` (`verified`/`verify_failed`,
    ohne Token-Wert).

### 7. Filament-Viewer `WebhookLogs`

Read-only `HasTable`-Page (Navigation aus, wie `SourceManagement`), erreichbar
über Header-Action **„Webhook-Protokoll"** auf `SourceManagement` (+ optional
per-Source-Action gefiltert). Tenant-/Sichtbarkeits-Scoping spiegelt
`SourceManagement`. Spalten: `created_at`, `event_type` (Badge), `driver`,
Quelle/Page, `outcome` (Badge, Farbe nach Erfolg/Fehler), `http_status`,
`message`. Filter: event_type, outcome, driver, Datum, Quelle. Row-Action
„Details" → Modal mit formatiertem `request`/`response`-JSON.

### 8. Konfiguration + Retention

Neuer Block in `config/lead-pipeline.php` unter `webhooks`:

```php
'logging' => [
    'enabled'        => env('LEAD_PIPELINE_WEBHOOK_LOG', true),
    'channel'        => 'lead-webhooks',
    'store_payload'  => true,   // PII-Kill-Switch
    'retention_days' => 30,
],
```

Command `lead-pipeline:prune-webhook-logs` löscht Zeilen älter als
`retention_days`; eingehängt in den bestehenden Tages-Schedule (04:00).
`enabled=false` → Logger ist No-Op.

## Teststrategie (TDD, red-first, `vendor/bin/pest --parallel`)

Pro Punkt zuerst ein fehlschlagender Test, dann Implementierung:

1. **Incoming** — je Outcome ein Feature-Test, der die Route trifft und die
   `lead_webhook_logs`-Zeile (event_type/outcome/http_status) assertet:
   created (201), source_inactive (404), rejected_signature (403), no_phase (422).
2. **Registration** — `Http::fake()` für `/subscribed_apps`: Erfolg → `subscribed`
   + Body geloggt; Fehler-Response → `subscribe_failed` + voller FB-Body geloggt.
3. **Status-Check** — `Http::fake()` subscribed_apps → `status_check`-Zeile mit roher Antwort.
4. **Verify** — GET-Handshake korrekt/falsch → `verified`/`verify_failed`.
5. **Redaction** — gespeicherter `request` enthält keinen `access_token`/Bearer/Signature.
6. **Retention** — alte + neue Zeilen anlegen, Command laufen lassen, nur alte gelöscht.
7. **Kill-Switch** — `enabled=false` → keine Zeile; `store_payload=false` → nur Metadaten.
8. **Filament-Page** — `livewire(WebhookLogs::class)` listet Records, Tenant-Scoping greift.
9. **Ausfallsicherheit** — Logger-Fehler (z. B. DB-Exception gemockt) bricht den
   Webhook-Pfad **nicht** (Lead wird trotzdem erstellt / 201).

## Release-Workflow

1. Branch im Klon `packages/john-wink/filament-lead-pipeline`.
2. TDD-Implementierung, `vendor/bin/pest --parallel`, `vendor/bin/pint`.
3. Commit, Tag `v0.1.46`, Push (GitButler-Workflow beachten).
4. Host: `composer update john-wink/filament-lead-pipeline` (Constraint `^0.1.37`
   erfüllt; ggf. auf `^0.1.46` anheben), `php artisan migrate` (neue Tabelle),
   `php artisan filament:optimize`. Page wird vom Plugin automatisch registriert.

## Risiken / offene Punkte

- **PII in DB + Logs** (bewusst gewählt): Retention 30 Tage + `store_payload`-Switch
  mildern. Filament-Page nur tenant-/rollengescoped sichtbar.
- **`FacebookGraphService`-Instanziierung**: prüfen, ob irgendwo `new` statt
  Container — sonst Logger-Injection nachziehen.
- **Host-Migration**: neue Tabelle muss auf Produktion migriert werden (Vapor).
