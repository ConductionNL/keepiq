# Tasks: a Firefox build that loads, and a Safari build

## 1. Manifests and builds

- [x] 1.1 Split the manifest into a base and Chromium and Firefox overlays in `build.mjs` and emit `dist/chromium` and `dist/firefox`. Verify: node test that each manifest has the keys its browser requires.
- [ ] 1.2 Add a Safari conversion step and notes for a macOS runner. Verify: build log from a macOS runner, or report it blocked. OPEN (DECISIONS row 20, 29 Sep): `browser-extension/safari/convert.sh` and its README are in place; it runs once a macOS runner and an Apple identity exist.
- [x] 1.3 Ask Firefox for the server's host without its port. Firefox 161 grants `http://localhost:8080/*` but still answers the extension's `POST /index.php/login/v2` with a CORS error, so pairing with a server on a non-default port said "Could not reach"; `http://localhost/*` works (found in the live walk of `extension-passkey-enrolment` 4.2, 25 Nov 2026). Verify: `src/accounts/normalize-server-url.test.ts` (`drops the port on Firefox`).

## 2. CI

- [x] 2.1 Add a workflow job that builds all packages and loads Chromium and Firefox headless to check the background starts. Verify: the workflow run. `.github/workflows/browser-extension.yml`; the Chromium check was run locally (service worker answered get-state; control: the Firefox package in Chromium fails with no service worker). The Firefox check first runs in CI on the PR. Done (21 Nov 2026): `scripts/firefox-smoke.ts` installs the Firefox package as a temporary add-on, requires its background running, opens the popup at the URL its policy gives and asks `settings.get` from it in the chrome context, with extensions in process; the `check-and-build` job passed on PR #1939 ("The background answered settings.get", "Firefox smoke test passed").

## 3. Close out

- [x] 3.1 Set row `clients-12` to built (or `building` with the Safari task named) and archive when all tasks are done. Verify: parity_verify --strict. Row set `building` with the Safari task named (2026-09-30); archive waits for 1.2.

