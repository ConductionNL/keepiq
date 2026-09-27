# Design: extension store release and one-time code on the next step

## Context

Code at development `4c214a9d`:

- `browser-extension/build.mjs:1` bundles five entries with esbuild into `browser-extension/dist/` and copies one `manifest.json`. It has no target option, no packing and no signing.
- `browser-extension/manifest.json` is a single MV3 manifest: `background.service_worker`, permissions `storage`, `activeTab`, `tabs`, `scripting`, `clipboardWrite`, `idle`, `windows`, optional `webAuthenticationProxy`, and host permissions for every `http` and `https` page. It has no `browser_specific_settings`. `scripting` has no call site in `browser-extension/src/` (`chrome.windows` is used by `browser-extension/src/passkey/orchestrator.js:45`). Its `description` contains an em-dash, which the store listing copy must not carry.
- `.github/workflows/` has `cli-release.yml` (Go CLI, `cli-v*` tags) and `release.yml` (the Nextcloud app); nothing builds or ships the extension. Extension tests live in `tests/extension/` and run under the root vitest.
- `lib/Controller/ExtensionController.php:123` returns `capabilities` from `pair()`, but no version.
- Code fill: `browser-extension/src/background/service-worker.js:113` `doFill()` fills the login, then `:137` computes the code for the tab host and `:141` sends one `fill-otp` message. `browser-extension/src/content/content-script.js:115` `fillOtp()` looks for a visible field matching `OTP_SELECTORS` (`:30`) once. If the field arrives on the next page, nothing fills it. The existing spec already allows filling "on the current or post-submit page (re-detecting after in-origin navigation)" (`openspec/specs/extension-totp-autofill/spec.md`, requirement "Optional OTP-field fill with fallback"), but the code never re-detects.
- The vault key lives only in the worker's memory (`browser-extension/src/lib/vault.js:27`); termination of the worker locks the vault.

## Goals / Non-Goals

**Goals:**

- A user installs Keepiq from the Chrome Web Store, Firefox Add-ons or Edge Add-ons.
- An administrator can force-install it for a whole organisation.
- Every published package is built by CI from a tagged commit, from source a reviewer can rebuild.
- The code fills itself on the second login step, on the same site, shortly after the password fill.

**Non-Goals:**

- Safari. It needs an Xcode wrapper app and Apple distribution; a later change.
- Self-hosted Chrome update manifests. Chrome only installs off-store extensions through enterprise policy anyway.
- Matching over shared and team-folder secrets: already returned by the match endpoint (see the proposal).
- Filling a code on a different site than the login (for example a separate identity provider domain). The intent is bound to the login's site on purpose.

## Decisions

### D1: One source tree, a manifest per browser

`build.mjs --target chrome` keeps today's manifest. `--target firefox` writes `browser_specific_settings.gecko.id` (`keepiq@conduction.nl`) and a minimum Firefox version, declares the worker bundle under `background.scripts` because Firefox runs MV3 backgrounds as event pages, and drops the Chrome-only `webAuthenticationProxy`. Edge uses the Chrome package. The manifest `version` comes from the tag.

Alternative considered: a cross-browser polyfill and one universal manifest. Rejected: Chrome rejects the Firefox keys and Firefox rejects `service_worker`, so a per-target manifest is simpler than a runtime shim.

### D2: A release workflow modelled on `cli-release.yml`

`.github/workflows/extension-release.yml` runs on pull requests and pushes touching `browser-extension/**`, `src/crypto/**` or `src/totp/**` (the extension bundles those web-app modules verbatim). It runs the `tests/extension` vitest suite, builds both targets twice and compares the hashes (a reproducibility check), runs `web-ext lint` on the Firefox build, and uploads both zips as artefacts.

On a tag `extension-v<semver>` a second job, bound to a protected GitHub environment `extension-stores` that needs a maintainer's approval, uploads and publishes to the Chrome Web Store API, signs and submits a listed version with `web-ext sign` to AMO (with the source archive and build steps AMO asks for bundled code), submits to the Edge Add-ons API, signs an unlisted Firefox package for self-hosting, and attaches everything to a GitHub release.

Alternative considered: publishing by hand from a maintainer's machine. Rejected: nobody could then show which commit a store package came from.

### D3: Store credentials only in the protected environment

The Chrome Web Store client id, client secret and refresh token, the AMO API issuer and secret, and the Edge client credentials are GitHub environment secrets of `extension-stores`. No pull-request workflow can read them. Placeholders in documentation look like `YOUR_AMO_JWT_ISSUER`.

### D4: Listings and permissions pass review

Each store listing carries a privacy policy page (in `docs/`) that states the zero-knowledge model: the extension sends the server only ciphertext and index fields, and stores only the pairing (server URL, user, app password) in extension storage. Each permission gets a one-line justification. `scripting` is removed because nothing calls it. The listing copy is written with the writing skill, so the manifest `description` loses its em-dash.

### D5: Version handshake

`pair()` adds `serverVersion` to its response. The extension carries a minimum server version constant and shows "Update Keepiq on your server to use this extension version" instead of failing on an unknown route. Store auto-updates can then move ahead of an organisation's server without silent breakage.

### D6: A one-shot code intent, bound to tab, site and time

After a successful login fill with a matched `totp` secret, the worker writes `{ tabId, site, totpSecretId, expiresAt }` to `chrome.storage.session`, where `site` is the registrable domain from `browser-extension/src/lib/match.js` and `expiresAt` is five minutes out. `storage.session` is held in memory by the browser and not written to disk. The intent holds no seed and no code.

The content script watches for a visible field matching `OTP_SELECTORS` (on load and through a throttled `MutationObserver`). When one appears it sends `otp-field-detected` with its own hostname. The worker fills only if all of these hold: an intent exists for `sender.tab.id`, the sender frame's registrable domain equals the intent's `site`, the intent has not expired, and the vault is unlocked. It then decrypts the seed, computes the current code, sends `fill-otp` to that frame only, and deletes the intent. A second field on a later page gets nothing.

Alternative considered: keeping the code itself in the intent. Rejected: a code is a credential for 30 seconds, and computing it fresh is cheap. Alternative considered: `chrome.webNavigation` to track the next page. Rejected: it needs a new permission, and an in-page step (a single-page app swapping the form) never navigates.

## Security and zero-knowledge

Nothing changes in what the server sees: ciphertext and the unencrypted `name` and `url` index fields, as ADR-003 and `browser-extension-autofill` require. The master password, the derived key and the vault `CryptoKey` stay in the worker's memory only.

The code intent in `storage.session` holds a tab id, a registrable domain, a secret id and an expiry. None of these is secret material. The seed is decrypted transiently in the worker when the code is computed, exactly as today (`service-worker.js:163`). A page cannot trigger a code fill on another site, on another tab, after five minutes, or twice.

Store credentials never reach the extension or the server. The signed packages contain only the bundled source that CI built from the tag.

## Risks / Trade-offs

- **The worker can be terminated between the two login steps.** Termination locks the vault, and a locked vault never fills. The user then unlocks and reads the code in the popup, as today. Keeping the worker alive longer would keep the key in memory longer, which this change does not do.
- **Store review can take days and can reject a release.** Releases are tagged separately from the app (`extension-v*`), so an app release never waits on a store.
- **Wide host permissions draw reviewer scrutiny.** They are needed to detect login fields on any site; the justification says so.
- **A site's code field may not match `OTP_SELECTORS`.** The clipboard copy stays as the fallback.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Tests use the existing `tests/extension` fixtures; a static test page with a two-step login form is added for the Playwright extension flow.

## Migration

None on the server: no table, no column, no `<version>` bump. The extension's own manifest `version` moves with each `extension-v*` tag.
