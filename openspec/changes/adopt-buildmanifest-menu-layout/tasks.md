# Tasks: Adopt buildManifest + menu-layout.json

> **Status: PARTIAL.** The user-facing ADR-044 outcome (the Applications entry
> moves out of the main scrollable nav into the settings foldout) is APPLIED
> directly via the entry's own `section: "settings"` field, which
> `@conduction/nextcloud-vue`'s `CnAppNav` natively groups on. The *shared
> `buildManifest(base, fragments, menuLayout)` pipeline* itself is BLOCKED:
> that util is **not published** in the installed `@conduction/nextcloud-vue`
> `beta.144` — the dist only exports `buildManifestFromStore` (a different,
> store-driven builder), and there is no `applySettingsSection` /
> `applyMenuRelocations` / `applyMenuRemovals` export. Swapping `main.js`'s
> `mergeManifestFragments` for a util that does not exist would break the build;
> reimplementing those primitives app-locally is exactly what ADR-044 §1
> forbids. So the fragment-merge stays until the util ships in nc-vue (a
> cross-app/nc-vue-repo step, out of scope for this apply pass).

## 1. Confirm the shared util's contract

- [x] 1.1 Read `@conduction/nextcloud-vue` for `buildManifest(base, fragments, menuLayout)` — **NOT FOUND** in the installed `beta.144` dist/src. Only `buildManifestFromStore` exists (store-driven, not fragment+layout-driven). The ADR-044 primitives (`applySettingsSection` etc.) are not exported.
- [x] 1.2 Confirm the util is exported from the depended-on version — it is not; would require bumping to a (not-yet-published) nc-vue that ships `buildManifest`. **BLOCKED on nc-vue publishing the util** (nc-vue lives in its own repo; not touched in this pass).

## 2. Add menu-layout.json

- [x] 2.1 Created `src/menu-layout.json` with `relocations: {}`, `removals: {}`, `settingsSection: ["ApplicationsMenu"]` — plus a `$note` documenting that it is the declarative source for when `buildManifest` lands, and that the interim placement is done directly via the entry's `section` field.
- [x] 2.2 Confirmed `ApplicationsMenu`'s manifest `id` matches the `settingsSection` string exactly (case-sensitive).

## 3. Replace the inline merge in main.js

- [ ] 3.1 Import `buildManifest` from `@conduction/nextcloud-vue` — **DEFERRED/BLOCKED**: export does not exist in beta.144 (see status banner).
- [ ] 3.2 Import `menuLayout` from `./menu-layout.json` — DEFERRED (only meaningful once `buildManifest` consumes it).
- [ ] 3.3 Replace `mergeManifestFragments` body with `buildManifest(...)` — DEFERRED/BLOCKED.
- [ ] 3.4 Delete `mergeManifestFragments` — DEFERRED: it still performs the (allowed) fragment-collection + concat step and there is no shared util to delegate to yet. Kept, with the swap tracked here.
- [ ] 3.5 Confirm `routesFromManifest()` shape — n/a until 3.3 lands.

## 4. Verify no route loss (ADR-029)

- [x] 4.1 The `ApplicationRegister` route (`/applications`, page id `ApplicationRegister`) is untouched in `manifest.json` `pages[]`; only the `ApplicationsMenu` **menu** entry's `section` changed. The route stays reachable via the foldout entry and direct deep-link. (Static verification; live route-reachability needs a running instance.)
- [x] 4.2 `CnAppNav` renders `section: "settings"` entries inside `NcAppNavigationSettings` with their icon + label — confirmed by reading `CnAppNav.vue` (`settingsItems` computed groups `section === 'settings'`).

## 5. Manual verification

- [ ] 5.1 Build + load in browser: main nav shows Dashboard / Vault only, foldout shows Applications — **needs a live instance** (build passes; DOM placement follows from `CnAppNav`'s section grouping). Not driven here.
- [ ] 5.2 Click through to Applications from the foldout; approve/reject still works — **needs a live instance**. Not driven here.
- [ ] 5.3 Run existing Playwright nav/dashboard specs — **needs a live instance**. Not driven here.

## 6. Tests

- [x] 6.1 No existing unit/e2e test asserts `ApplicationsMenu` placement in the scrollable nav (grep of `src/` + `tests/` for `ApplicationsMenu` returns only `manifest.json`), so nothing needed updating for the move.
- [ ] 6.2 Add a regression test asserting `settingsSection` entries render in the foldout — **DEFERRED**: a meaningful assertion depends on the `buildManifest` adoption (3.x); until then the interim placement is a static `section` field on the manifest entry, covered by the existing manifest schema. Tracked with 3.x.
