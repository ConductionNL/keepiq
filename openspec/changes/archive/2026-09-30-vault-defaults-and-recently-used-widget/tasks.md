# Tasks: default item type and view, and a recently used list on the dashboard

## 1. Defaults

- [x] 1.1 Add `DefaultsSection.vue` to personal settings, delete `DashboardSettingsView.vue`, and save through the settings store. Verify: vitest on the section. The Playwright flow is excluded (the browser service is not available to this lane); the scenarios carry the reason.
- [x] 1.2 Read `default_secret_type` in `SecretCreateDialog.vue` and `default_view` in `SecretList.vue`, with the fallback to `login`. Verify: vitest for the saved and the missing type.

## 2. Recently used

- [x] 2.1 Add `GET /api/v1/secrets/recent` returning distinct secrets from `recentlyAccessed()` filtered to the caller's live secrets. Verify: PHPUnit for distinctness, ordering and a deleted secret; hydra route-auth and no-admin-idor gates.
- [x] 2.2 Add the widget to `src/manifest.json` with a row route to the secret. Verify: `npm run check:manifest`; the Playwright flow is excluded, the scenario carries the reason.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [x] 3.2 Set rows `vault-20` and `vault-21` to built, close the defect on `vault-20`, archive the change. Verify: parity_verify --strict.

