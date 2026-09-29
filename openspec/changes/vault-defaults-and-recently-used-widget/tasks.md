# Tasks: default item type and view, and a recently used list on the dashboard

## 1. Defaults

- [ ] 1.1 Add `DefaultsSection.vue` to personal settings, delete `DashboardSettingsView.vue`, and save through the settings store. Verify: vitest on the section, and a Playwright flow save then reload.
- [ ] 1.2 Read `default_secret_type` in `SecretCreateDialog.vue` and `default_view` in `SecretList.vue`, with the fallback to `login`. Verify: vitest for the saved and the missing type.

## 2. Recently used

- [ ] 2.1 Add `GET /api/v1/secrets/recent` returning distinct secrets from `recentlyAccessed()` filtered to the caller's live secrets. Verify: PHPUnit for distinctness, ordering and a deleted secret; hydra route-auth and no-admin-idor gates.
- [ ] 2.2 Add the widget to `src/manifest.json` with a row route to the secret. Verify: Playwright flow open two secrets, open the dashboard, click a row.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set rows `vault-20` and `vault-21` to built, close the defect on `vault-20`, archive the change. Verify: parity_verify --strict.

