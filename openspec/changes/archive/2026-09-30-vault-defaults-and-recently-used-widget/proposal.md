---
kind: code
---

# Default item type and view, and a recently used list on the dashboard

## Why

The backend keeps two per-user preferences, `default_secret_type` and `default_view` (`lib/Service/SettingsService.php:98-99`), but the only form that edits `default_view` is `src/views/DashboardSettingsView.vue`, which no route mounts, and neither the create dialog nor the list reads them (issue #208 is recorded on the matrix row). On the dashboard, `recent-activity-feed` lists the last five audit events of any kind (`src/manifest.json:294`), while `AuditService::recentlyAccessed()` (`lib/Service/AuditService.php:200`) is a finished query with no caller. Both rows are in the vault area, the core area, and both are wiring of finished backend parts, so one change fixes the two.

The rows share one screen or service, so they are one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `vault-20` | Choose a default item type and default view for new items. | `no`: `no`: the server stores `default_secret_type` and `default_view` but no reached screen edits them and nothing reads them |
| `vault-21` | See the secrets you used most recently on the dashboard. | `partial`: `partial`: the dashboard shows the last five audit events of any kind; the recently-accessed query has no caller |

### Demand

- `vault-20`: no demand row.
- `vault-21`: no demand row.

### Competitors rated yes

- `vault-20`: no competitor rated yes.
- `vault-21`: no competitor rated yes.

## What Changes

- Mount the preferences form in personal settings so a user can pick a default item type and a default list view.
- Preselect the saved default type in the create dialog, and open the secret list in the saved view.
- Add a Recently used widget to the dashboard, fed by `recentlyAccessed()`, one row per secret (not per event), where a row opens the secret.

## Capabilities

### New Capabilities

- `vault-defaults`
- `vault-recently-used`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: a preferences section in personal settings, `SecretCreateDialog.vue`, `SecretList.vue`, a new dashboard widget entry in `src/manifest.json`.
- **Backend**: one route that returns recently read secrets joined to the caller's secrets, using `AuditService::recentlyAccessed()`.
- **Database**: none.
- **l10n**: new strings in every shipped locale.
