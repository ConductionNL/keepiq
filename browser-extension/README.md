# Keepiq

Browser extension for Keepiq, the encrypted secrets manager for Nextcloud. Fill in logins, passkeys and one-time codes on any site, and save new ones as you go. Everything is encrypted — your master password and your secrets never reach the server.

A browser extension built with [WXT](https://wxt.dev), shipping to **Chrome (MV3)
and Firefox (MV2)** from one source.

## Development

```sh
npm install            # postinstall runs `wxt prepare`
npm run dev            # Chromium dev + HMR, auto-launches a browser
npm run dev:firefox    # Firefox dev (MV2) + HMR
```

Non-standard browser install? Copy `web-ext.config.example.ts` to
`web-ext.config.ts` (gitignored) and point it at your binary.

## Checks & builds

```sh
npm run typecheck      # tsc --noEmit — does NOT build
npm run lint           # eslint .  (lint:fix to autofix)
npm run build          # Chrome production → .output/chrome-mv3/
npm run build:firefox  # Firefox production → .output/firefox-mv2/
npm run zip            # store-submission zip (zip:firefox for MV2)
```

Build **both** targets before calling a browser-facing change done — manifest
version and runtime API differences surface at build or runtime, not at typecheck.

## Loading a build

- **Chrome** — `chrome://extensions` → *Developer mode* → *Load unpacked* →
  `.output/chrome-mv3/`
- **Firefox** — `about:debugging#/runtime/this-firefox` → *Load Temporary Add-on…*
  → `.output/firefox-mv2/manifest.json`

## Layout

```
entrypoints/     one file (or directory) per extension entrypoint
  background.ts  MV3 service worker / MV2 background page
  content.ts     injected into matching pages
  popup/         toolbar popup
src/             shared modules, imported as `@/src/…`
public/          copied verbatim into the build (icons live here)
```

## Releasing

Bump `version` in `package.json` — WXT derives the manifest version from it — then
publish a GitHub Release. The `Release` workflow builds and attaches both zips.

## Reading

[WXT-AND-BROWSERS.md](WXT-AND-BROWSERS.md) — the cross-browser rules: `browser.*`
vs `chrome.*`, the MV3/MV2 split, message-passing gotchas. Read it before touching
`entrypoints/`.
