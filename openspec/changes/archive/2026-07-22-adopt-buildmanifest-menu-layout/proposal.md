---
kind: code
---

# Proposal: Adopt the shared `buildManifest` pipeline + `menu-layout.json`

## Why

`src/main.js:80-95` (`mergeManifestFragments`) is a hand-rolled, ~15-line re-implementation of the manifest-fragment merge that hydra ADR-044 ("Menu architecture — shared buildManifest pipeline, settings-foldout, and cards-collapse") requires every manifest-v2 app to get from `@conduction/nextcloud-vue`'s `buildManifest(base, fragments, menuLayout)`:

```js
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [...(base.menu || [])] }
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) merged.pages.push(...frag.pages)
		if (Array.isArray(frag.menu)) merged.menu.push(...frag.menu)
	})
	return merged
}
```

This concatenates `pages`/`menu` only — it has no concept of `relocations`, `removals`, or `settingsSection`, the three primitives ADR-044 §2 defines via `menu-layout.json`. Doriath has neither a `src/menu-layout.json` file nor any call to the shared `buildManifest` util (verified: `grep -rn "buildManifest" src/` returns nothing).

The concrete consequence is visible in `src/manifest.json:23-29`: the `ApplicationsMenu` entry (the application-registration/approval queue — "integration & connection plumbing" in ADR-044's own placement table) sits in the main scrollable nav at `order: 30`, directly between `Vault` (daily-use, order 20) and the footer items — instead of the settings foldout (`NcAppNavigationSettings`) where ADR-044 places setup/admin/plumbing entries. As Doriath adds more admin surfaces (CA management, secret-type definitions, dashboard settings — all currently reached only via direct routes with no menu entry at all, another symptom of not having gone through the shared pipeline's placement model) the un-triaged main nav will keep growing.

ADR-044 §1 states plainly: "No app may re-implement `mergeMenuItems` / `applyMenuRelocations` / `applyMenuRemovals` / `applySettingsSection` inline." Doriath's `mergeManifestFragments` is exactly that reimplementation, just a smaller (feature-incomplete) one.

## What Changes

- Replace `mergeManifestFragments` in `src/main.js` with the shared `buildManifest(base, fragments, menuLayout)` import from `@conduction/nextcloud-vue`; keep the `require.context('./manifest.d/', false, /\.json$/)` collection step (the one app-local step ADR-044 §1 still allows) and pass its resolved fragments array into `buildManifest`
- Add `src/menu-layout.json` declaring:
  - `settingsSection`: `["ApplicationsMenu"]` (application registration/approval queue — config/admin plumbing, not daily-use)
  - `relocations`: `{}` initially (no group restructuring needed yet — Doriath's nav is shallow)
  - `removals`: `{}` initially
- Verify via ADR-029 (route-reachability gate) that moving `ApplicationsMenu` out of the main nav does not remove its route — `ApplicationRegister` must stay routable from the settings foldout entry
- Not BREAKING for end users of the API; visually relocates one menu entry (Applications) from main nav to the settings foldout — call out in the app's release notes
