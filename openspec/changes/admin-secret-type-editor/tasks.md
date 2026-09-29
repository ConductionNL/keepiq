# Tasks: an administrator defines item types and the fields they carry

## 1. Data and API

- [ ] 1.1 Add the migration for `fields` and extend the entity and `SecretTypeService` validation (unique keys, at most 30 fields, known kinds). Verify: PHPUnit for valid, duplicate key and unknown kind.
- [ ] 1.2 Accept and return `fields` on the create and update routes. Verify: PHPUnit on the controller; hydra route-auth and semantic-auth gates.

## 2. Admin page

- [ ] 2.1 Build the Item types admin view and route with a field list editor, in its own dialog files. Verify: vitest on the editor; Playwright flow create a type as admin.

## 3. Typed form

- [ ] 3.1 Render a generic typed form in the create and edit dialogs for types with fields and store values in the encrypted blob. Verify: vitest for required and hidden; Playwright flow create and open a typed item.

## 4. Close out

- [ ] 4.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 4.2 Set row `admin-18` to built and archive the change. Verify: parity_verify --strict.

