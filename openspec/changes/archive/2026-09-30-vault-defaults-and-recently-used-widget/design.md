# Design: default item type and view, and a recently used list on the dashboard

## Context

At development `156cd800`:

- `lib/Service/SettingsService.php:98-99` holds `default_secret_type` (default `login`) and `default_view` (default `list`); `src/store/modules/dashboardSettings.js` already allow-lists `default_view`.
- `src/views/DashboardSettingsView.vue:36` binds `form.default_view` and is in no route or registry (matrix defect, issue #208).
- `src/components/settings/SessionTimeoutSection.vue` shows the pattern for a mounted personal settings section.
- `lib/Service/AuditService.php:200` `recentlyAccessed()` calls `findRecentReadsByActor()`; the audit rows carry an object id and a name.
- `src/manifest.json:294` is the `recent-activity-feed` widget on `/api/v1/audit/me`.

## Goals / Non-Goals

**Goals**
- Preferences that the server already stores take effect.
- A user sees the secrets they used last, not a log of events.

**Non-Goals**
- A last-used timestamp on the secret row; that belongs to `vault-favourites-tags-and-last-used`.
- Per-folder defaults.

## Decisions

### D1: Mount a section, not the old view

The old `DashboardSettingsView` mixes unrelated keys. A small `DefaultsSection.vue` next to `SessionTimeoutSection.vue` edits only the two keys and reuses the store; the dead view is deleted.

### D2: Distinct secrets from the audit query

The widget endpoint asks `recentlyAccessed()` for more rows than it shows, groups by secret id and drops ids the user no longer holds, so a secret opened twice appears once.
