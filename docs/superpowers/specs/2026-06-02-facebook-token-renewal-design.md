# Facebook-Token-Renewal & Paragon-Härtung — Design

**Datum:** 2026-06-02
**Paket:** `john-wink/filament-lead-pipeline`
**Status:** Genehmigt (Brainstorming)

## Kontext & Problem

In Production ist der Facebook-Token abgelaufen und die Lead-Synchronisation stoppte. Der
`RefreshFacebookTokens`-Job existiert und ist in der Host-App (`bootstrap/app.php:43-45`,
`->dailyAt('03:30')`) geplant — die Ursache liegt **nicht** in fehlender Registrierung,
sondern in fehlender Ausfallsicherheit. Drei verifizierte Auslöser:

1. **Einzelner transienter Fehler killt die Verbindung dauerhaft.**
   `RefreshFacebookTokens.php:44-45` fängt jede `Exception` und setzt sofort `status='expired'`
   — ohne Retry/Backoff. Die nächste Ausführung filtert `where('status','connected')`
   (Zeile 30), wodurch eine einmal abgelaufene Verbindung **permanent** von jedem weiteren
   Refresh ausgeschlossen ist. Nur manuelles Neu-Login heilt sie.
2. **Ausführung hängt am Queue-Worker.** `$schedule->job(...)` enqueued nur; auf Vapor/Lambda
   (SQS, `queue-timeout: 895`) wird der Job bei Worker-Problemen nie ausgeführt — der Token
   altert still über 60 Tage hinaus.
3. **Keine HTTP-Timeouts/Retries** in `FacebookGraphService`: ein hängender Request blockiert
   bis zum Queue-Timeout; jeder kurze Netzwerkfehler reißt den ganzen Lauf mit (→ Punkt 1).

### Facebook-Token-Modell (Erwartungsmanagement)

Gespeichert wird ein **Long-Lived User Token** (~60 Tage). Lead-Abrufe laufen über
**Page-Tokens**, die bei jedem Sync aus dem User-Token neu abgeleitet werden. Solange der
User-Token **vor** Ablauf via `fb_exchange_token` verlängert wird (resettet 60 Tage), läuft
alles unbegrenzt. Ist er **abgelaufen**, sind auch die Page-Tokens wertlos und **kein**
Mechanismus kann das ohne menschliches Neu-Login reparieren.

→ „Login erneuern" bedeutet zwei Dinge:
**(a)** Token automatisch am Leben halten (selbstheilend, transiente Fehler überstehen),
**(b)** wenn (a) terminal scheitert: proaktive Warnung + geführter 1-Klick-Re-Auth.

## Ziele

- Selbstheilendes Token-Renewal: transiente Fehler werden überlebt, nur terminale Fehler
  (Token wirklich ungültig) führen zu `NeedsReauth`.
- Token-Ausfall wird in **allen** Konsum-Pfaden erkannt (Refresh, Sync, Import, Webhook),
  nicht nur im Refresh-Job.
- Das Paket stellt **nur Events** bereit; Notifications/Alerting-Policy bleibt der Host-App.
- Vapor-taugliche Ausführung: synchroner Scheduled-Command, optional per-Connection-Queue.
- Paragon-Qualität: Token-Redaction, Webhook-Idempotenz, atomare Sortierung, saubere
  Auth-Schemata, vollständige Pest-Tests (immer `--parallel`).

## Nicht-Ziele

- Kein Wechsel auf Facebook System-User-Tokens oder Business-Login-Migration (separates Projekt).
- Keine fertigen Mail/Slack-Notifications im Paket (bewusst dem Host überlassen).
- Keine UI-Überarbeitung jenseits des Token-Health-Indikators + Reconnect-Action.

---

## Architektur

### A — Token-State-Modell

**Neues Enum** `src/Enums/FacebookConnectionStatusEnum.php` (string-backed):

| Wert          | Bedeutung                                                              |
|---------------|------------------------------------------------------------------------|
| `Connected`   | Token gültig/gesund (umfasst „transient fehlgeschlagen, wird retried"). |
| `NeedsReauth` | Terminal: User muss neu einloggen. Ersetzt das alte `'expired'`.       |
| `Disabled`    | Vom Nutzer/Admin manuell deaktiviert; kein Auto-Refresh.               |

> Bewusst **kein** persistenter `Refreshing`-Status: transiente Fehlversuche werden über
> `refresh_attempts`/`refresh_failed_at` abgebildet, nicht über einen Status. Das hält die
> State-Machine minimal.

**Migration `0023_add_token_health_to_facebook_connections_table.php`** ergänzt:

- `last_refreshed_at` (timestamp, nullable) — letzter erfolgreicher Refresh.
- `acquired_at` (timestamp, nullable) — Erstausstellung (OAuth-Callback).
- `refresh_attempts` (unsignedTinyInteger, default 0) — aufeinanderfolgende transiente Fehler.
- `refresh_failed_at` (timestamp, nullable) — Zeitpunkt des letzten transienten Fehlers (Backoff-Basis).
- `last_error` (text, nullable) — redigierte Fehlermeldung (nie Token-Material).
- `expiring_soon_notified_at` (timestamp, nullable) — Vorwarnung nur einmal pro Fenster feuern.

Daten-Migration: bestehende `status='expired'` → `'needs_reauth'`, `'connected'` bleibt.
Die Spalte `status` bleibt `string` (DB), wird im Model auf das Enum gecastet.

**Model `FacebookConnection`**:
- `casts()`: `'status' => FacebookConnectionStatusEnum::class`, neue Timestamps als `datetime`.
- `isConnected()` via Enum; `isExpired()` wird zu `needsReauth(): bool` (klare Semantik);
  neu `isInWarningWindow(CarbonInterface $now): bool`.
- Neuer Scope `scopeDueForRefresh($query, CarbonInterface $window)` kapselt die Auswahl-Query
  (siehe C) an genau einer Stelle.

### B — `FacebookGraphService`: Fehler klassifizieren statt verschlucken

**Neue Exceptions** unter `src/Exceptions/`:
- `FacebookTokenInvalidException` (terminal) — Token ungültig/abgelaufen.
- `FacebookTransientException` (transient) — vorübergehend, soll retried werden.
- (Basis) `FacebookGraphException` — gemeinsame Oberklasse.

**Klassifikation** aus der Graph-Antwort `{"error":{"code":..,"error_subcode":..,"type":..}}`:
- **Terminal** → `FacebookTokenInvalidException`: HTTP 401, oder Graph `code === 190`
  (inkl. Subcodes wie 463/467 = expired/invalidated).
- **Transient** → `FacebookTransientException`: HTTP 429/5xx, Graph `code ∈ {4, 17, 32, 613}`
  (Rate-Limits/temporär), sowie `Illuminate\Http\Client\ConnectionException`.
- Alles andere → generische `FacebookGraphException` (wird vom Caller als Fehler behandelt,
  aber **nicht** als Token-Tod).

> 🔧 **User-Contribution (Business-Logik):** Die exakte Code→Klasse-Zuordnung in
> `classifyError(int $httpStatus, ?array $error): FacebookGraphException` ist die Stelle, an
> der Domänenwissen über Facebooks Fehlercodes zählt. Signatur + Default werden vorbereitet;
> die ~8 Zeilen Mapping füllt der Autor.

**Shared Request-Builder** `client(): PendingRequest`:
`Http::asForm()->timeout(10)->connectTimeout(5)->retry(2, 200, throwsOnTransientOnly)`.
Alle 10 Graph-Methoden nutzen ihn statt direkter `Http::get()`-Aufrufe.

**Token-Redaction:** Fehler werfen nie mehr `$response->body()` roh. Ein
`sanitize(string $body): string` ersetzt Token-Parameter (`access_token=...`,
`appsecret_proof=...`) durch `[REDACTED]`. Gilt auch für geloggte Felder.

**Optional empfohlen:** `appsecret_proof` (HMAC-SHA256 des Tokens mit App-Secret) an
Server-seitigen Calls — verhindert Missbrauch eines geleakten Tokens.

### C — Renewal-Engine (Ausführung: Command + optionale Queue)

**Command** `src/Commands/RefreshFacebookTokensCommand.php`
(`lead-pipeline:facebook:refresh-tokens`): läuft **synchron** im Scheduler-Lambda.

Auswahl-Query (`FacebookConnection::dueForRefresh()`):
```
status = Connected
AND (
      token_expires_at <= now() + warning_window   -- im Gefahrenfenster
   OR refresh_failed_at IS NOT NULL                 -- zuvor transient gescheitert
   OR token_expires_at IS NULL                      -- defektes Bookkeeping → behandeln
)
```
Pro Connection: inline verarbeiten **oder** `RefreshFacebookConnection`-Job dispatchen
(`config('lead-pipeline.facebook.refresh.queue')` bzw. `--queue`). Default inline.

**Per-Connection-Logik** (`RefreshFacebookConnection` Job, von Command/Queue aufgerufen):

1. **Backoff-Gate:** Wenn `refresh_failed_at` gesetzt und `now() < refresh_failed_at + backoff(refresh_attempts)` → überspringen.
2. Token via `refreshLongLivedToken()` verlängern.
3. **Response-Validierung:** `access_token` non-empty **und** `expires_in` ein positiver int,
   sonst → wie transienter Fehler behandeln (kein Überschreiben mit Müll).
4. **Erfolg:** `access_token`, `token_expires_at = now()+expires_in`, `last_refreshed_at=now()`,
   `refresh_attempts=0`, `refresh_failed_at=null`, `last_error=null`; Page-Resync; Event
   `FacebookTokenRefreshed`.
5. **`FacebookTransientException` (oder ungültige Response):** `refresh_attempts++`,
   `refresh_failed_at=now()`, `last_error=<redacted>`. Status **bleibt** `Connected`.
   Event `FacebookTokenRefreshFailed(connection, attempt)`.
   **Eskalation:** wenn `refresh_attempts >= maxAttempts` **und** Token tatsächlich abgelaufen
   (`token_expires_at` in der Vergangenheit) → wie terminal behandeln.
6. **`FacebookTokenInvalidException` (terminal):** `status = NeedsReauth`; zugehörige
   `LeadSource` auf `Error` (mit redigierter Message); Event `FacebookConnectionNeedsReauth`.

**Backoff:** `backoff(n) = min(maxBackoff, base * 2^(n-1))` (config: `base=300s`, `max=6h`,
`maxAttempts=5`).

> 🔧 **User-Contribution:** `shouldEscalate(FacebookConnection $c): bool` und die
> Backoff-Parameter sind die zweite Stelle für Domänen-Policy.

**Proaktiver Vorwarn-Scan** (im selben Command, vor dem Refresh): Connections, die neu ins
Warnfenster (7 Tage) eintreten und `expiring_soon_notified_at IS NULL` haben → Event
`FacebookTokenExpiringSoon(connection, daysLeft)` einmalig feuern, `expiring_soon_notified_at`
setzen. Unabhängig vom Refresh-Erfolg, damit der Host warnen kann, bevor etwas bricht.

### D — Events statt Notifications (Paket-Boundary)

Neue Events unter `src/Events/` (alle tragen die `FacebookConnection`):
- `FacebookTokenRefreshed`
- `FacebookTokenExpiringSoon` (+ `int $daysLeft`)
- `FacebookTokenRefreshFailed` (+ `int $attempt`, redigierter Grund)
- `FacebookConnectionNeedsReauth` (+ string `$reason`)
- `FacebookConnectionReconnected` (nach erfolgreichem Re-Auth im OAuth-Callback)

Das Paket **dispatcht nur Events**. Die bestehende `FacebookConnectionExpired`-Notification
wird nicht mehr automatisch gefeuert; sie bleibt als optionales Beispiel und wird im README
als „so kann ein Host auf `FacebookConnectionNeedsReauth` reagieren"-Listener dokumentiert.

### E — Konsum-Pfade selbstheilend

`SyncFacebookPages` (fängt heute `Throwable` still), `ImportFacebookLeadsJob` und der zentrale
Webhook fangen künftig `FacebookTokenInvalidException` → Connection `NeedsReauth` + Event
`FacebookConnectionNeedsReauth`. So wird Token-Tod überall erkannt. `FacebookTransientException`
in Jobs → `release()` mit Backoff (statt sofortigem Markieren).

### F — Webhook-Härtung

- **Idempotenz:** Migration `0024_add_external_id_to_leads_table.php` fügt `external_id`
  (string, nullable) hinzu, mit **Unique-Constraint** auf `(<source-fk>, external_id)`
  (FK-Spalte je nach `primary_key_type` via `Lead::fkColumn('lead_source')`). Webhook-Pfad und
  Import-Job setzen `external_id = leadgen_id` und prüfen vor dem Insert (bzw. fangen den
  Unique-Verstoß). Ersetzt die fragile `whereJsonContains('raw_data->id', ...)`-Dedup.
- **Auth-Schemata trennen:** `WebhookController::handle` reicht den Bearer-Token **nicht** mehr
  als HMAC-Signatur an Meta-Sources durch. HMAC-Driver (Meta) prüfen ausschließlich
  `X-Hub-Signature-256`; Token-Driver (Zapier/Api) prüfen den Bearer. Jeder Driver zieht sich
  via `verifyRequest(Request, LeadSource)` selbst, was er braucht (sauberere Contract-Methode
  als das aktuelle `verifySignature(payload, signature, source)`).
- **ACK statt 500:** Im zentralen Webhook wird `getLeadData()` in try/catch gekapselt. Bei
  `FacebookTokenInvalidException` → Connection `NeedsReauth` + Event, Response **200**
  (stoppt die 36h-Retry-Kaskade und Abo-Deaktivierung). Verpasste Leads werden nach Reconnect
  per `ImportFacebookLeadsJob` (Zeitfenster) nachgeholt.

### G — Atomare Sort-Vergabe

Das an drei Stellen wiederholte `max('sort')+1` wird zu `Lead::nextSortForPhase($phaseKey): int`
mit `lockForUpdate()` in einer Transaktion. Beseitigt kollidierende `sort`-Werte bei parallelen
Webhook-Deliveries.

### H — Reconnect-UX in `SourceManagement` / `MetaDriver`

- **Token-Health-Badge** an Meta-Sources: `Verbunden` / `läuft in X Tagen ab` / `Re-Auth nötig`
  (abgeleitet aus Connection-Status + `token_expires_at`).
- **`reconnect`-TableAction** (analog zur bestehenden `reactivate_webhook`-Action): sichtbar,
  wenn `NeedsReauth` oder im Warnfenster; löst den OAuth-Redirect
  (`route('lead-pipeline.facebook.redirect')`) aus. Nutzt das bestehende
  `facebook-connect-button`-Pattern.

### I — Scheduling / Vapor

- **Auto-Registrierung** im ServiceProvider via
  `$this->callAfterResolving(Schedule::class, fn (Schedule $s) => ...)`, **config-gesteuert**
  (`lead-pipeline.facebook.refresh.enabled`, `.cadence` default `hourly`), mit
  `->withoutOverlapping()->onOneServer()`. So ist jede künftige Installation safe-by-default.
- **Host-`bootstrap/app.php`**: der bestehende `$schedule->job(new RefreshFacebookTokens())`
  -Eintrag wird durch `$schedule->command('lead-pipeline:facebook:refresh-tokens')->hourly()`
  ersetzt. Der alte Bulk-Job `RefreshFacebookTokens` wird **entfernt** und durch den Command
  (Scan/Vorwarnung) + den per-Connection-Job `RefreshFacebookConnection` (eigentlicher Refresh)
  abgelöst.
- **Health-Event** `FacebookRefreshHealthCheckFailed`: feuert, wenn die jüngste
  `last_refreshed_at` aller aktiven Connections älter als _N_ Stunden ist (config) — der Host
  kann daraus „Scheduler/Queue läuft nicht"-Alerts bauen.

### J — Cleanup

- Toten `getMigrations()`-Override im ServiceProvider entfernen (wird von Spatie nicht genutzt,
  listet veraltet nur bis `0018`).
- `FacebookConnectionExpired`-Notification aus dem Job-Pfad lösen (siehe D).

### K — Tests (Pest, immer `--parallel`)

Neue/erweiterte Feature-Tests:
- Refresh: transienter Fehler → Retry, **kein** `NeedsReauth`, `refresh_attempts++`.
- Refresh: Graph 190 → `NeedsReauth` + `FacebookConnectionNeedsReauth`-Event.
- Refresh: Eskalation nach `maxAttempts` + echtem Ablauf.
- Vorwarnung: Eintritt ins 7-Tage-Fenster → `FacebookTokenExpiringSoon` genau einmal.
- Bookkeeping: NULL `token_expires_at` wird einbezogen; ungültige Response überschreibt Token nicht.
- Webhook-Idempotenz: doppelte Delivery desselben `leadgen_id` → genau **ein** Lead.
- Auth-Schemata: Bearer ersetzt **nicht** HMAC bei Meta; gültiges `X-Hub-Signature-256` wird akzeptiert.
- Atomare Sortierung: zwei „gleichzeitige" Inserts → eindeutige `sort`-Werte.
- Konsum-Pfade: 190 in Sync/Import/Webhook → `NeedsReauth` + Event; transient → release.
- Reconnect-Action: sichtbar bei `NeedsReauth`, löst Redirect aus.
- Token-Redaction: Token taucht nie in `last_error`/Exception-Message/Log auf.
- Health-Event: keine erfolgreiche Aktualisierung seit N Stunden → `FacebookRefreshHealthCheckFailed`.

Bestehende Tests (`JobsTest`, `FacebookOAuthCallbackTest`, `WebhookMetaAttributionTest`,
`FacebookPageSynchronizerTest`, `FacebookReactivateWebhookActionTest`) an Enum/Events anpassen.

---

## Datenfluss (Happy Path + Heilung)

```
OAuth-Callback → Long-Lived Token (acquired_at, token_expires_at=+60d, status=Connected)
      │
      ▼
stündlicher Command  ──(im 7-Tage-Fenster?)──►  fb_exchange_token
      │                                              │
      │                                   ┌──────────┴───────────┐
      │                                Erfolg                 Fehler
      │                                   │              ┌───────┴────────┐
      │                          +60d, attempts=0    transient        terminal(190)
      │                          FacebookTokenRefreshed   │                │
      │                                            attempts++,      status=NeedsReauth
      │                                            bleibt Connected  Event + LeadSource=Error
      │                                            Event(Failed)            │
      ▼                                                                     ▼
Webhook/Import/Sync ──(190 erkannt)──► status=NeedsReauth + Event ──► Host alarmiert (Listener)
                                                                          │
                                                              SourceManagement: Reconnect-Klick
                                                                          │
                                                              OAuth-Redirect → neuer Token
                                                              FacebookConnectionReconnected
```

## Risiken & Abwägungen

- **Enum-Migration** berührt Job/Factory/bestehende Tests → in Reihenfolge B→A→C zuerst die
  Grundlagen, Tests laufend grün halten.
- **`external_id` Unique** muss den konfigurierbaren PK (uuid|id) respektieren → Migration
  konditional auf `Lead::fkColumn('lead_source')`.
- **ACK-statt-500** im Webhook bedeutet bewusst, dass ein Lead bei Token-Tod kurzzeitig nicht
  sofort ankommt; Backfill via Import nach Reconnect ist der Trade-off gegen die
  Abo-Deaktivierung.
- **appsecret_proof** ist optional; falls die FB-App es nicht erzwingt, nur additiv.

## Umsetzungsreihenfolge

B (Exceptions/HTTP) → A (State/Migration) → C+D (Engine+Events) → E (Konsum) →
F+G (Webhook/Sort) → H (UX) → I (Scheduling/Host) → J (Cleanup) → K (Tests durchgehend).
