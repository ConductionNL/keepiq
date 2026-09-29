# Design: an inactivity lock that follows activity, and a timeout the user keeps

## Context

At development `156cd800`:

- `src/store/modules/session.js:41` initialises `lastActivity`; `:121` and `:159` set it at unlock; `:194` `checkTimeout` compares against it; `:204` `updateActivity()` is never called.
- `src/App.vue:137-143` renders an in-memory timeout select; `:873-874` `saveTimeout` maps `session`, 10 and 30 minutes and falls back with `|| 600000`; `:497` always starts at `session`; `:779` polls `checkTimeout`.
- `src/components/settings/SessionTimeoutSection.vue:52-66` does GET and PUT of `session_timeout` and is mounted nowhere.
- `browser-extension/src/background/service-worker.js:37-38` already has a true idle lock; the extension is not changed.

## Goals / Non-Goals

**Goals**
- The lock means inactivity, everywhere in the web app.
- A timeout choice is remembered.

**Non-Goals**
- An administrator maximum (`admin-24`, decided no).
- Changing the extension idle lock.
- Lock on system sleep.

## Decisions

### D1: Throttle activity events

A single listener set on `document` calls `updateActivity()` at most once every 15 seconds, so typing does not cost a store write per key.

### D2: Hold the value as milliseconds with an explicit never

The store keeps `null` for Nextcloud session and a number for 10 or 30 minutes, so the falsy-zero fallback that caused the defect cannot recur.

### D3: One control, and it saves

Corrected at build time (29 Sep). keepiq's personal settings are the user-settings dialog in `App.vue`, which already held the select; `SessionTimeoutSection.vue` was a second, never-mounted copy with a native select. So the select in the user-settings dialog stays and becomes the one control: it reads `sessionStore.timeoutChoice` and saves through `saveTimeoutPreference()` (`PUT /api/settings/user`). The unmounted `SessionTimeoutSection.vue` is deleted. The saved value is loaded when the app mounts, so a reload followed by an unlock uses it.

### D4: An unset timeout is ten minutes

Once Nextcloud session really means no idle timer, the old unset default (`'session'` in `SettingsService::getUserPreferences()` and `AdminSettingsService`) would have switched the idle lock off for every user who never chose. In practice the vault always locked after ten minutes, so the unset default becomes `10min` (`AdminSettingsService::DEFAULT_SESSION_TIMEOUT`). Nextcloud session is now always a choice someone made.
