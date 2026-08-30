# Tasks: Adopt buildManifest + menu-layout.json

## 1. Confirm the shared util's contract

- [x] 1.1 Read `@conduction/nextcloud-vue`'s `buildManifest(base, fragments, menuLayout)` source/tests to confirm its exact fragment-array shape (matches what `require.context(...).keys().map(ctx)` produces) and its `menuLayout` shape (`relocations`/`removals`/`settingsSection`)
- [x] 1.2 Confirm the util is already exported from the package version doriath depends on (`package.json` `@conduction/nextcloud-vue` range); bump if the export was added in a later beta

## 2. Add menu-layout.json

- [x] 2.1 Create `src/menu-layout.json`:
  ```json
  {
    "relocations": {},
    "removals": {},
    "settingsSection": ["ApplicationsMenu"]
  }
  ```
- [x] 2.2 Confirm `ApplicationsMenu`'s manifest `id` in `src/manifest.json` matches the string used in `settingsSection` exactly (case-sensitive)

## 3. Replace the inline merge in main.js

- [x] 3.1 Import `buildManifest` from `@conduction/nextcloud-vue` in `src/main.js`
- [x] 3.2 Import `menuLayout` from `./menu-layout.json`
- [x] 3.3 Replace the `mergeManifestFragments` function body with a call to `buildManifest(bundledManifest, fragments, menuLayout)`, keeping the existing `require.context('./manifest.d/', false, /\.json$/)` collection step to build the `fragments` array
- [x] 3.4 Delete the now-dead `mergeManifestFragments` function entirely (no re-implementation left behind)
- [x] 3.5 Confirm `routesFromManifest(mergedManifest)` still receives a `pages[]` array in the same shape (buildManifest's output contract must match what the router builder expects)

## 4. Verify no route loss (ADR-029)

- [x] 4.1 Run the route-reachability gate / manual check: confirm `ApplicationRegister` route still resolves at `/apps/doriath/#/applications` (or its declared path) after `ApplicationsMenu` moves to the settings foldout
- [x] 4.2 Confirm the settings foldout (`NcAppNavigationSettings`) renders the relocated `ApplicationsMenu` entry with its icon + label intact

## 5. Manual verification

- [x] 5.1 Build (`npm run build` or dev watch), load Doriath in the browser, confirm: main nav shows Dashboard / Vault only (plus footer items); settings foldout (bottom-left gear) shows Applications
- [x] 5.2 Click through to Applications from the foldout; confirm the pending-applications admin view still loads and functions (approve/reject)
- [x] 5.3 Run existing Playwright e2e suite for navigation/dashboard specs to confirm no broken selectors from the menu move

## 6. Tests

- [x] 6.1 Update any existing unit/e2e test that asserts on the main-nav DOM structure (selector for `ApplicationsMenu` in the scrollable nav) to instead assert it's in the settings foldout
- [x] 6.2 Add a regression test (unit or e2e) asserting `menu-layout.json`'s `settingsSection` entries are actually present in the rendered foldout
