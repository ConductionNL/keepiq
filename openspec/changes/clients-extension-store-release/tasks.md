# Tasks: extension store release and one-time code on the next step

## 1. Build per browser

- [x] 1.1 Add `--target chrome|firefox` to `browser-extension/build.mjs`, writing the Firefox manifest keys from D1 and a version taken from an `EXTENSION_VERSION` variable. Verify: a vitest in `tests/extension/` builds both targets into a temp dir and asserts the Firefox manifest has `browser_specific_settings.gecko.id` and `background.scripts`, and the Chrome manifest has `background.service_worker`. Done: the per-browser build already existed (`manifests/browsers.mjs`, `--browser chromium|firefox`); `--target chrome|firefox`, `--outdir` and `EXTENSION_VERSION` are added. Test: `tests/extension/storeRelease.spec.js`.
- [x] 1.2 Remove the unused `scripting` permission and replace the manifest `description` with store copy written with the writing skill. Verify: `grep -rn "chrome.scripting" browser-extension/src` returns nothing, and the dash grep on `manifest.json` is clean. Done: `tests/extension/storeRelease.spec.js` (every permission has a call site, no `scripting`, no dash).

## 2. Release pipeline

- [ ] 2.1 (built; green run owed on the PR: the reproducibility and web-ext lint steps passed locally, zip packing needs the runner) Add `.github/workflows/extension-release.yml` for pull requests and pushes touching `browser-extension/**`, `src/crypto/**` or `src/totp/**`: vitest `tests/extension`, both builds, a second build with a hash comparison, `web-ext lint`, and zip artefacts. Verify: the workflow runs green on the pull request that adds it.
- [ ] 2.2 (built; the self-hosted Firefox file is the AMO-signed listed file, attached by the `attach-amo-signed` job after review (decision 2 Oct, keepiq#783), guarded by `tests/extension/storeRelease.spec.js`; the dry run needs store accounts and credentials, owed) Add the tag job for `extension-v*` bound to the protected `extension-stores` environment: Chrome Web Store upload and publish, AMO listed signing with the source archive, Edge Add-ons submission, unlisted Firefox signing, and a GitHub release with all packages. Verify: a dry run on a pre-release tag against the stores' test or unlisted channels, checked by hand.
- [ ] 2.3 (written in `docs/browser-extension/release.md`; who approves the environment is a POLICY.md question) Document the store credentials each secret holds, with placeholder values only, and who approves the environment. Verify: manual review; gitleaks passes.

## 3. Store listings and rollout

- [ ] 3.1 (written: `docs/browser-extension/privacy.md`, `docs/browser-extension/permissions.md`; review against the store checklists owed) Write the privacy policy page and one justification per permission in `docs/`, with the writing skill. Verify: manual review against the writing rules and the stores' listing checklists.
- [ ] 3.2 (written: `docs/browser-extension/rollout.md`; the store ids and the policy install test wait on the listings) Document enterprise rollout: Chrome and Edge `ExtensionInstallForcelist` by store id, and the signed Firefox package with Firefox enterprise policy. Verify: manual install through policy on one Chrome and one Firefox profile.

## 4. Version handshake

- [x] 4.1 Add `serverVersion` to the `pair()` response in `lib/Controller/ExtensionController.php`. Verify: PHPUnit `ExtensionControllerTest` asserts the field equals the installed app version. Done: `ExtensionControllerTest::testPairSucceeds` asserts `serverVersion`.
- [x] 4.2 Add a minimum server version constant to the extension and an "update your server" state in the popup. Verify: vitest with a mocked pair response below the minimum asserts the popup shows the update state and sends no match request. Done: `browser-extension/src/lib/version.js`, popup `view-update`; `tests/extension/serverVersion.spec.js`.

## 5. One-time code on the next step

- [x] 5.1 After a login fill with a matched `totp` secret, write the one-shot intent to `chrome.storage.session` in `service-worker.js`, and clear all intents on lock and unpair. Verify: vitest with a mocked `chrome.storage.session` asserts the stored intent holds no seed and no code, and that lock clears it. Done: `tests/extension/otpNextStep.spec.js`.
- [x] 5.2 In `content-script.js`, watch for a visible code field on load and through a throttled `MutationObserver`, and send `otp-field-detected` once per field. Verify: vitest with a jsdom page that inserts the field after a delay asserts exactly one message. Done: `browser-extension/src/content/otp-watch.js`; `tests/extension/otpNextStep.spec.js`.
- [x] 5.3 In the worker, fill only when the tab, the registrable domain, the expiry and the unlocked state all match, then delete the intent. Verify: vitest covers a fill on the same site, and refusals for another tab, another site, an expired intent, a locked vault and a second field. Done: `doOtpFieldDetected` in `browser-extension/src/background/router.js`; `tests/extension/otpNextStep.spec.js`.
- [x] 5.4 Add a Playwright flow with the unpacked Chrome build: a vault owner fills a login on a two-step test page and the code field on step two is filled. Verify: the Playwright spec passes locally and in the E2E job. Done 4 Oct: `tests/e2e/workflows/extension-otp-next-step.spec.ts` builds the Chromium package, loads it unpacked, pairs and unlocks through the real action popup, fills the login on step one of a two-step page served by `context.route()`, waits for the intent (site and secret id only), and checks step two's code field holds the code the web app's own TOTP module computes. Red before, green after: with the intent awaited, development never filled step two, because the next-step `fill-otp` message named no host and the content script fills only for its own host since #934 (`frameMayFill`); `doOtpFieldDetected` now names the reporting frame's host (`tests/extension/otpNextStep.spec.js` "names the host the content script checks", red on the old router). Green on Nextcloud 35 in 35 s. An earlier version of the spec passed on the broken code by a race (the fill-time code message landed on step two), which is why it now waits for the intent.

## Acceptance criteria

- A tagged `extension-v*` release produces signed packages for Chrome, Firefox and Edge from CI, attached to a GitHub release, after a maintainer approves the store job.
- Two builds of the same commit produce identical packages.
- No store credential is readable from a pull-request workflow.
- The extension tells the user when the paired server is older than it supports.
- The one-time code fills on the next login step on the same site within five minutes, at most once, and only while the vault is unlocked.
- The pending code intent never holds a seed or a code.
