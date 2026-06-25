# Lead Kanban — non-clickable card contacts & Meta Conversion-Leads feedback

Date: 2026-06-25
Status: approved

## Feature 1 — Card phone/email not clickable

**Problem:** On the kanban lead card, phone and email are `tel:`/`mailto:` links with
`@click.stop` + `logContact()`. The `.stop` swallows the card's `open-lead-detail`
click, so trying to open the modal lands the user in their mail/phone client.

**Change:** In `resources/views/kanban/lead-card-inline.blade.php`, render phone & email
as plain `<span>` text (no anchor, no `@click.stop`, no `logContact`). A click anywhere
on the card opens the detail modal. The clickable, logged `tel:`/`mailto:` contact
actions remain in the detail modal (`lead-detail-modal.blade.php`), so the logged-contact
feature is preserved.

## Feature 2 — "Nicht qualifiziert" phase + Meta lead-quality feedback

### 2A — Disqualified phase (mandatory, terminal)

- New `LeadPhaseTypeEnum::Disqualified = 'disqualified'`: terminal (`isTerminal()`),
  `defaultDisplayType()` = List, distinct color/label.
- Extend every Won/Lost special-case to include Disqualified where the logic is
  "terminal phase": `LeadDetailModal` (add a "Nicht qualifiziert" action like
  mark-as-lost), `LeadCard` drag handling, `LeadBoardResource` (`['won','lost']` checks),
  analytics/aggregators as appropriate.
- **Mandatory on every board:** seed it for new boards (default-phase seeding) and a
  migration that backfills a Disqualified phase onto every existing board that lacks one
  (ordered after Lost, List display, type `disqualified`).

### 2B — Meta Conversion-Leads feedback (config-gated, queued)

Meta optimises lead quality via the CRM→Conversions API feedback loop: report lead
outcomes back; Meta learns from positive (quality) and negative (non-quality) signals.
There is no rigid Meta enum — event names are mapped in Events Manager. Hardwired,
Meta-aligned names:

| Phase type | event_name |
|---|---|
| Won | `closed_won` |
| Lost | `closed_lost` |
| Disqualified | `disqualified` |

- **Trigger:** `LeadObserver::updated` — when a lead enters a terminal phase
  (Won/Lost/Disqualified) AND it is a Meta lead (source driver = `meta` and
  `external_id` / leadgen id present), dispatch a queued `ReportLeadOutcomeToMeta` job.
- **Job:** POST to the Conversions API `/{dataset_id}/events`:
  `event_name` (mapping above), `action_source: "system_generated"`,
  `event_time`, `user_data: { lead_id: <external_id> }`. Non-blocking; logged.
- **Config** `config/lead-pipeline.php` → `meta.conversions`:
  `enabled` (bool, default false), `dataset_id`, `access_token`,
  optional `event_map` override. Empty / disabled → no-op (config-gated).
- Only Meta-sourced leads with a leadgen id are reported; others are skipped.

## Testing

- F1: card blade renders phone/email without `tel:`/`mailto:`/`logContact`; modal still has them.
- 2A: enum is terminal + List; migration backfills a Disqualified phase on a board without one; board with one is untouched.
- 2B: terminal transition on a Meta lead dispatches the job with the mapped event_name +
  lead_id; non-Meta lead does not; disabled/empty config → no dispatch (no-op).

## Rollout

Separate plugin releases: F1, then 2A, then 2B. Host bumps via composer per
[[project_plugin_dev_dependency]].
