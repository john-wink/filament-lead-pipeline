# Building Integrations

A guide for building a third-party integration that plugs into the lead pipeline — e.g. a dialer, a WhatsApp provider, or anything else that wants a presence on the Integrations page, the board edit form, the lead detail modal, and the activity timeline. Written so you can build one without reading the package internals.

> Naming note: this guide uses **Acme Dialer** as a placeholder integration throughout. Swap it for your own name/key.

## Table of Contents

- [Overview](#overview)
- [Contract Reference](#contract-reference)
- [Activity Convention](#activity-convention)
- [LeadCreated & Origin](#leadcreated--origin)
- [Settings Component](#settings-component)
- [Testing](#testing)
- [Full Example](#full-example)

---

## Overview

An integration is a plain PHP class implementing `JohnWink\FilamentLeadPipeline\Contracts\LeadIntegrationContract`. Once registered, it can contribute to four surfaces:

| Surface | Contract method | Where it renders |
|---|---|---|
| Integrations page | `settingsComponent()` | A settings island (your own Livewire component) inside a card on the Integrations page, one card per registered integration |
| LeadBoard edit form | `boardFormComponents()` | Extra form schema components appended to the **edit** form only |
| Lead detail modal | `leadModalActions()` / `handleLeadAction()` | Action buttons in the modal's action row, and the handler that runs when one is clicked |
| Activity timeline | `renderActivity()` | Custom markup for `Integration`-type activity entries, instead of the generic entry |

### Registration

Register integration classes on the plugin, wherever you register the plugin itself (typically your panel's `PanelProvider`):

```php
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use App\LeadIntegrations\AcmeDialer\AcmeDialerIntegration;

$panel->plugin(
    FilamentLeadPipelinePlugin::make()
        ->integrations([
            AcmeDialerIntegration::class,
        ])
);
```

A few things to know about registration:

- `integrations()` takes an array of **class-strings**, not instances. Each class is resolved through the container via `app($class)` the first time integrations are needed, so constructor dependency injection works normally.
- Resolution is memoized per plugin instance — `getIntegrations()` only instantiates each class once.
- The Integrations page (`Filament\Pages\IntegrationsPage`) is only added to the panel's pages when `integrations()` is non-empty. Register zero integrations and the page/route simply doesn't exist for that panel.
- If two registered classes resolve to the same `key()`, `getIntegrations()` throws `InvalidArgumentException` naming both classes. This is checked eagerly the first time integrations are resolved (page load, board edit, or modal open) — pick a key that's unique across every integration registered on that panel.

---

## Contract Reference

`LeadIntegrationContract` has 9 methods. The single most important thing in the interface is the **trust boundary** documented on the interface itself:

> The constructor, `key()`, `label()` and `icon()` form the trusted identity of an integration: they must be side-effect-free and must **never throw** — they run unguarded during registry resolution and in list renders. All other methods are called fail-closed (a throw disables the integration for that render instead of breaking the page).

Concretely: `IntegrationsPage`'s Blade view calls `$integration->icon()` and `$integration->label()` directly in a `@foreach`, with no `try`/`catch` — if either throws, the whole Integrations page breaks for every integration, not just yours. Every other method is always called from inside a `try`/`catch (Throwable)` block by the package, so a throw there degrades gracefully.

| # | Method | Contract |
|---|---|---|
| 1 | `key(): string` | Unique registry key, e.g. `'acme-dialer'`. Trusted identity — never throws. Duplicate keys across registered integrations throw `InvalidArgumentException` from `getIntegrations()`. |
| 2 | `label(): string` | Human-readable name shown on the Integrations page card and on modal action buttons. Trusted identity — never throws. |
| 3 | `icon(): string` | Heroicon name, e.g. `'heroicon-o-phone'`. Trusted identity — never throws. |
| 4 | `isActivatedFor(Model $tenant): bool` | Whether the integration is configured/active for the given tenant. Fail-closed: every caller wraps this in `try`/`catch` and treats a throw as `false` (inactive). Called on the Integrations page (per card badge), on the board edit form (gates `boardFormComponents()`), and in the lead modal (gates `leadModalActions()`). |
| 5 | `settingsComponent(): string` | Returns the Livewire component class rendered as the settings island for this integration's card. Fail-closed: a throw skips rendering the island for that card only — the rest of the Integrations page still renders. |
| 6 | `boardFormComponents(LeadBoard $board): array` | Extra schema components appended to the **LeadBoard edit form only** — see below. Fail-closed, and only called after `isActivatedFor()` returns `true` for the current tenant; either method throwing skips this integration's contribution to that form render (not the whole form). |
| 7 | `leadModalActions(Lead $lead): array<IntegrationActionData>` | Actions offered for this lead in the lead detail modal. Fail-closed and only called after `isActivatedFor()` passes. An empty array (or a throw) hides this integration's action row entirely for that lead — it is not shown as an empty/disabled block. |
| 8 | `handleLeadAction(string $actionKey, Lead $lead): void` | Executes a modal action. See the whitelist rule below. A throw surfaces a danger notification with the exception message as its body. |
| 9 | `renderActivity(LeadActivity $activity): ?View` | Custom rendering for `Integration`-type timeline entries, or `null` to fall back to the generic entry. Runs **even after deactivation** — see below. |

### `boardFormComponents()` — edit form only

The board edit form's schema is built once with `$form->getRecord()` available; on the **create** form there is no record yet, so `LeadBoardResource::getIntegrationBoardFormComponents()` short-circuits to `[]` before your method is ever called. Don't rely on `boardFormComponents()` running during board creation — if you need setup at creation time, do it in `isActivatedFor()`/elsewhere or have the user configure it after saving.

The components you return are spread directly into the resource's top-level `schema([...])` array, alongside the package's own `Section`s — so return a `Forms\Components\Section::make(...)->schema([...])` (or similar) rather than a bare field, to get a visually consistent block on the form. Use dot-path state, e.g. `Toggle::make('settings.acme_dialer_auto_call')`, to persist into the board's `settings` JSON column — that's the same convention the package itself uses for board-level flags (see `LeadBoardResource`'s `settings.auto_move_on_assign_phase` field).

### `leadModalActions()` / `handleLeadAction()` — the whitelist

The lead detail modal computes `leadModalActions()` once per render (request-cached in `LeadDetailModal::integrationModalActions()`) and only shows a button for actions that call is currently returning. When a button is clicked, `runIntegrationAction($integrationKey, $actionKey)`:

1. Re-reads the **currently offered** action keys for that integration (not what was offered when the button was drawn).
2. If `$actionKey` isn't in that list — because the integration deactivated itself, stopped offering that action, or the request was tampered with — `handleLeadAction()` is **never called**, and the user gets the `lead-pipeline::lead-pipeline.integrations.action_failed` danger notification.
3. Only an action key that survives that check reaches your `handleLeadAction()`.
4. If `handleLeadAction()` throws, the same danger notification is shown, with the exception's message as the notification body — so don't put anything sensitive in that message.
5. On success, the lead's activities are reloaded and an `action_success` notification is shown.

`IntegrationActionData` (the DTO returned by `leadModalActions()`) has `key`, `label`, `icon`, `color` (`primary`/`info`/`success`/`danger`/`warning`/`gray`, mapped to pre-compiled Tailwind classes), `requiresConfirmation` and an optional `confirmText`. If `requiresConfirmation` is `true` and `confirmText` is omitted, the modal falls back to the generic `lead-pipeline::lead-pipeline.integrations.confirm_action` translation for the `wire:confirm` prompt.

### `renderActivity()` — runs after deactivation too

This is the one method that is **not** gated behind `isActivatedFor()`. The contract is explicit about why:

> This runs even for tenants where `isActivatedFor()` is false, so historical activities keep their rich rendering after deactivation. Render from the activity's stored properties only — never from live connection state.

In other words: don't call out to your external API, don't check `isActivatedFor()` yourself, and don't read anything but `$activity->properties` (and other stored `LeadActivity` columns) inside `renderActivity()`. A lead that was called via Acme Dialer last year must still render that history correctly even if the tenant disconnected Acme Dialer today.

Returning `null`, or throwing, both fall back to the generic activity entry (the `LeadActivityTypeEnum::Integration` label/icon/color plus the stored `description`).

---

## Activity Convention

To have an activity render through your `renderActivity()` instead of the generic entry, create it with:

```php
$lead->activities()->create([
    'type'        => \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Integration->value,
    'description' => 'Fallback text shown if renderActivity() ever returns null',
    'properties'  => [
        'integration' => $this->key(), // must exactly match key()
        // ...whatever else your renderActivity() needs, e.g. call duration, outcome
    ],
]);
```

`LeadDetailModal::integrationActivityView()` reads `$activity->properties['integration']`, resolves the integration by that key via `FilamentLeadPipelinePlugin::get()->getIntegration($key)`, and calls `renderActivity($activity)` on it. It falls back to the generic entry whenever:

- the activity's `type` isn't `LeadActivityTypeEnum::Integration`,
- `properties['integration']` is missing, blank, or not a string,
- the key doesn't resolve to any registered integration,
- resolving the plugin/integration throws, or
- `renderActivity()` itself returns `null` or throws.

Because the description is always stored, the generic fallback is never blank — treat `renderActivity()` as a richer *presentation* of data you've already committed to `properties`, not the only place that data lives.

---

## LeadCreated & Origin

`JohnWink\FilamentLeadPipeline\Events\LeadCreated` carries a `LeadOriginEnum $origin` (default `Realtime`):

```php
enum LeadOriginEnum: string
{
    case Realtime = 'realtime';
    case Import   = 'import';
    case Manual   = 'manual';
}
```

Verified dispatch sites in this package:

| Origin | Dispatched from |
|---|---|
| `Realtime` (default — no explicit argument) | Generic webhook driver (API/Zapier) processing in `WebhookController`; the Meta/Facebook real-time `leadgen` webhook |
| `Manual` | Public funnel submission (`FunnelWizard`); manually created leads from the Kanban board (`Livewire\KanbanBoard`, `Filament\Pages\KanbanBoard`) |
| `Import` | Bulk historical imports — `ImportFacebookLeadsJob`, `ImportImmoScoutLeadsJob` |

**If your integration listens for `LeadCreated` to trigger an automatic outbound action (e.g. auto-dialing a new lead), gate it on `Realtime`:**

```php
use JohnWink\FilamentLeadPipeline\Enums\LeadOriginEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;

class AutoCallNewLead
{
    public function handle(LeadCreated $event): void
    {
        if (LeadOriginEnum::Realtime !== $event->origin) {
            return;
        }

        // ... place the call
    }
}
```

Without that check, a historical import of thousands of old Facebook/ImmoScout leads — or a staff member keying in a lead manually or via a funnel — would trigger the same outbound action as a genuinely new inbound lead. `Import` and `Manual` leads are real leads and still get everything else (board placement, activities, field mapping); they just shouldn't retroactively fire time-sensitive automation that assumes "this just happened."

---

## Settings Component

`settingsComponent()` returns a Livewire component class-string. It's rendered once per integration card on the Integrations page via:

```blade
@livewire($settingsComponent, key('integration-settings-' . $integration->key()))
```

Two things to get right:

1. **Authorize inside the component.** `IntegrationsPage` has no `canAccess()` override of its own — `$shouldRegisterNavigation = false` only hides it from the sidebar, it does not restrict who can hit the route. Any authenticated user who can reach that panel/tenant can load the page. If your settings expose credentials, tokens, or destructive actions, `Gate::authorize(...)` (or an equivalent policy/permission check) **inside your Livewire component**, not the page.
2. **Keep the key a simple slug.** `key()` gets interpolated straight into the Livewire wire key above, and into `@js($integration->key())` inside the lead modal's `wire:click` payload, and stored verbatim in `LeadActivity.properties['integration']`. Nothing in the package validates its format, but nothing quotes/escapes it for you either — stick to something like `'acme-dialer'` (lowercase, hyphenated, no spaces or special characters).

---

## Testing

The package's own test suite exercises the contract's fail-closed behavior with a fixture at `tests/Fixtures/Integrations/FakeIntegration.php`. It's a useful template for testing your own integration (or the package's behavior around it) without live credentials: each fail-closed method reads a dedicated boolean config flag under `lead-pipeline.testing.*` and either takes the happy path, an alternate path, or throws — so a test flips one config value instead of mocking the whole interface.

| Config flag | Effect |
|---|---|
| `fake_integration_active` | `isActivatedFor()` return value |
| `fake_integration_activation_throws` | `isActivatedFor()` throws |
| `fake_integration_settings_component_throws` | `settingsComponent()` throws |
| `fake_integration_board_components` | `boardFormComponents()` returns a real `Toggle` instead of `[]` |
| `fake_integration_board_throws` | `boardFormComponents()` throws |
| `fake_integration_no_actions` | `leadModalActions()` returns `[]` |
| `fake_integration_actions_throws` | `leadModalActions()` throws |
| `fake_integration_confirm` | The offered action sets `requiresConfirmation: true` |
| `fake_integration_throws` | `handleLeadAction()` throws |
| `fake_integration_renders` | `renderActivity()` returns a view (`true`, default) vs `null` (`false`) |
| `fake_integration_render_throws` | `renderActivity()` throws |

```php
config()->set('lead-pipeline.testing.fake_integration_active', true);
config()->set('lead-pipeline.testing.fake_integration_throws', true);

livewire(LeadDetailModal::class)
    ->call('runIntegrationAction', 'fake', 'ping')
    ->assertNotified(__('lead-pipeline::lead-pipeline.integrations.action_failed'));
```

There's also `tests/Fixtures/Integrations/DuplicateKeyFakeIntegration.php`, which deliberately resolves to the same `key()` as `FakeIntegration` — used only to exercise the registry's duplicate-key guard.

### Zero-integrations boot state

Some behavior only differs between "no integrations registered on this panel at all" and "integrations registered but inactive for this tenant." `tests/Fixtures/ZeroIntegrationsPanelProvider.php` boots a second panel (id `zero-integrations`) with `->integrations([])`, so its `IntegrationsPage` route never gets registered. Wire it into a specific test file with the `RegistersZeroIntegrationsPanel` trait:

```php
uses(\JohnWink\FilamentLeadPipeline\Tests\Fixtures\Concerns\RegistersZeroIntegrationsPanel::class);
```

That trait overrides `TestCase::additionalPackageProviders()` — the extension point `tests/TestCase.php` provides exactly for this: an empty-by-default hook that individual test files can override via Pest's `uses()` to boot an extra panel/provider, without changing every other test's panel setup.

---

## Full Example

A minimal, compiling integration skeleton. Copy this into your own app/package and adjust.

```php
<?php

declare(strict_types=1);

namespace App\LeadIntegrations\AcmeDialer;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Contracts\LeadIntegrationContract;
use JohnWink\FilamentLeadPipeline\DTOs\IntegrationActionData;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use RuntimeException;
use Throwable;

class AcmeDialerIntegration implements LeadIntegrationContract
{
    public function key(): string
    {
        return 'acme-dialer';
    }

    public function label(): string
    {
        return 'Acme Dialer';
    }

    public function icon(): string
    {
        return 'heroicon-o-phone-arrow-up-right';
    }

    public function isActivatedFor(Model $tenant): bool
    {
        return filled($tenant->settings['acme_dialer_api_key'] ?? null);
    }

    public function settingsComponent(): string
    {
        return AcmeDialerSettings::class;
    }

    /** @return array<int, mixed> */
    public function boardFormComponents(LeadBoard $board): array
    {
        return [
            Section::make('Acme Dialer')
                ->schema([
                    Toggle::make('settings.acme_dialer_auto_call')
                        ->label('Auto-call new leads on this board'),
                ]),
        ];
    }

    /** @return array<int, IntegrationActionData> */
    public function leadModalActions(Lead $lead): array
    {
        if (blank($lead->phone)) {
            return [];
        }

        return [
            new IntegrationActionData(
                key: 'call',
                label: 'Call via Acme Dialer',
                icon: 'heroicon-o-phone-arrow-up-right',
                color: 'primary',
                requiresConfirmation: true,
                confirmText: sprintf('Call %s now?', $lead->phone),
            ),
        ];
    }

    public function handleLeadAction(string $actionKey, Lead $lead): void
    {
        try {
            // AcmeDialerClient::make()->call($lead->phone);
        } catch (Throwable $exception) {
            throw new RuntimeException('Acme Dialer call failed: ' . $exception->getMessage());
        }

        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Integration->value,
            'description' => sprintf('Called %s via Acme Dialer', $lead->phone),
            'properties'  => [
                'integration' => $this->key(),
                'phone'       => $lead->phone,
            ],
        ]);
    }

    public function renderActivity(LeadActivity $activity): ?View
    {
        return view('lead-integrations.acme-dialer.activity', [
            'phone' => $activity->properties['phone'] ?? null,
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\LeadIntegrations\AcmeDialer;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class AcmeDialerSettings extends Component
{
    public function mount(): void
    {
        // The Integrations page has no canAccess() gate of its own —
        // this component must authorize itself.
        Gate::authorize('manage-lead-integrations');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('lead-integrations.acme-dialer.settings');
    }
}
```

Registration:

```php
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use App\LeadIntegrations\AcmeDialer\AcmeDialerIntegration;

$panel->plugin(
    FilamentLeadPipelinePlugin::make()
        ->integrations([
            AcmeDialerIntegration::class,
        ])
);
```

Optional `LeadCreated` listener for realtime auto-calling (registered in your `EventServiceProvider`, see [LeadCreated & Origin](#leadcreated--origin) above):

```php
use JohnWink\FilamentLeadPipeline\Enums\LeadOriginEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;

class AutoCallNewLeadViaAcmeDialer
{
    public function handle(LeadCreated $event): void
    {
        if (LeadOriginEnum::Realtime !== $event->origin) {
            return;
        }

        if (! ($event->lead->board?->settings['acme_dialer_auto_call'] ?? false)) {
            return;
        }

        // ... place the call
    }
}
```
