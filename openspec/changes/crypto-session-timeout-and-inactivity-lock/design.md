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

### D3: One control

The select in `App.vue` is removed and the settings section is the only place to change it, so the saved and the applied value cannot diverge.
