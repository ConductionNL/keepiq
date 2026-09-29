# Tasks: a Firefox build that loads, and a Safari build

## 1. Manifests and builds

- [ ] 1.1 Split the manifest into a base and Chromium and Firefox overlays in `build.mjs` and emit `dist/chromium` and `dist/firefox`. Verify: node test that each manifest has the keys its browser requires.
- [ ] 1.2 Add a Safari conversion step and notes for a macOS runner. Verify: build log from a macOS runner, or report it blocked.

## 2. CI

- [ ] 2.1 Add a workflow job that builds all packages and loads Chromium and Firefox headless to check the background starts. Verify: the workflow run.

## 3. Close out

- [ ] 3.1 Set row `clients-12` to built (or `building` with the Safari task named) and archive when all tasks are done. Verify: parity_verify --strict.

