# Design: a Firefox build that loads, and a Safari build

## Context

At development `156cd800`:

- `browser-extension/manifest.json:3` `manifest_version` 3; `:22` declares only `background.service_worker`.
- `browser-extension/build.mjs:24` builds one bundle for `chrome110` and `firefox110` targets.
- `.github/workflows` holds `cli-release.yml` only; the extension has no CI build.

## Goals / Non-Goals

**Goals**
- Each browser gets a package it can load.

**Non-Goals**
- Store listings and signing (`clients-extension-store-release`).
- Opera and other Chromium forks beyond what the Chromium package covers.

## Decisions

### D1: Templates over one manifest

A base manifest plus a small per-browser overlay keeps one source of truth and makes the differences reviewable.

### D2: Safari is a separate task that may block

It needs macOS. It is last, so a missing runner cannot hold up the Firefox fix.
