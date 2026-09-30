# Tasks: an administrator defines item types and the fields they carry

## 1. Data and API

- [x] 1.1 Add the migration for `fields` and extend the entity and `SecretTypeService` validation (unique keys, at most 30 fields, known kinds). Verify: PHPUnit for valid, duplicate key and unknown kind.
- [x] 1.2 Accept and return `fields` on the create and update routes. Verify: PHPUnit on the controller; hydra route-auth and semantic-auth gates.

## 2. Admin page

- [x] 2.1 Build the Item types admin view and route with a field list editor, in its own dialog files. Verify: vitest on the editor and the section. Built as a section of the admin settings page, not an app route (design D3). The Playwright flow is excluded (browser service not available to this lane); the scenario carries the reason.

## 3. Typed form

- [x] 3.1 Render a generic typed form in the create and edit dialogs for types with fields and store values in the encrypted blob. Verify: vitest for required and hidden in both dialogs. The Playwright flow is excluded; the scenarios carry the reason.

## 4. Close out

- [x] 4.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [x] 4.2 Set row `admin-18` to built and archive the change. Verify: parity_verify --strict.
