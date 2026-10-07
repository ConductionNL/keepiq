---
kind: code
---

# A Firefox build that loads, and a Safari build

## Why

The extension has one `browser-extension/manifest.json` with `background.service_worker` (`:22`) and `build.mjs:24` targets `chrome110` and `firefox110`. Firefox MV3 needs `background.scripts`, so the Firefox build probably fails to start; there is no Safari project. Four competitors rate yes. Store publication is covered by `clients-extension-store-release`; this change makes the builds themselves right.

One row, one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `clients-12` | Use browser extensions for Chrome, Firefox, Edge and Safari. | `partial`: `partial`: one MV3 manifest declares only a service worker, so Firefox probably does not start it; there is no Safari build; only source installs exist |

### Demand

- `clients-12`: no demand row.

### Competitors rated yes

- `clients-12`, bitwarden: "bitwarden/clients@web-v2026.9.0 apps/browser/package.json:7 build:chrome, plus build:firefox, build:edge, build:opera, build:safari; apps/browser/src/manifest.json, manifest.v3.json Note: One extension codebase built for Chrome, F"
- `clients-12`, onepassword: "https://releases.1password.com/b5x/stable/ : Chrome, Edge, Brave, Firefox and Safari extensions"
- `clients-12`, passbolt: "passbolt/passbolt_browser_extension@v5.16.0 src/chrome, src/chrome-mv3, src/firefox, src/safari build targets (Edge uses the Chromium build) Note: The extension is built for Chrome and other Chromium browsers including Edge, Firef"
- `clients-12`, keeper: "https://docs.keeper.io/user-guides/browser-extensions : KeeperFill for Chrome, Firefox, Safari, Microsoft Edge, Opera and other Chromium browsers"

## What Changes

- Generate a per-browser manifest in `build.mjs`: `service_worker` for Chromium, `scripts` plus `browser_specific_settings.gecko` for Firefox.
- Add a Safari web extension conversion step and document it, run on a macOS runner.
- Add a CI job that builds all three and loads Chromium and Firefox headless to check the background starts.

## Capabilities

### New Capabilities

- `clients-browser-builds`

### Modified Capabilities

- None in delta form.

## Impact

- **Extension**: `browser-extension/build.mjs`, manifest templates, CI workflow.
- **Backend**: none.
- **Risk**: the Safari step needs macOS and an Apple developer identity to sign; producing the unsigned project is in scope, signing and store release are `clients-extension-store-release`. If no macOS runner is available the Safari task stays open and is reported, and the other tasks still ship.
