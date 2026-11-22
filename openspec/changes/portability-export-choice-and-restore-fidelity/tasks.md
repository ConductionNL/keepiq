# Tasks: choose what goes into an export, and restore a backup without losing types

## 1. Export

- [x] 1.1 Extend `serializeVault()` with the combined scope, the field deny list, `typeName` and `typeLabel`, and the partial marker; fix the test fixtures to use UUID type ids. Verify: vitest for each scope, each left-out field, a custom type, and a UUID type id resolving to its name.
- [ ] 1.2 Rebuild the scope and fields part of `ExportDialog.vue` (entire vault, folders, types, selection; fields to leave out). Verify: vitest on `buildScope()`, and a Playwright flow export two folders and one type.
  - Round 2 (2026-11-17): built. `src/export/exportScope.js` `buildScope()`; the dialog offers entire vault, chosen folders, chosen types and the selection, plus Leave out (usernames, additional fields, authenticator seeds), and passes the vault's types so the file names them. vitest `tests/vitest/export-scope.spec.js` against the real serializer. Playwright flow in `tests/e2e/workflows/export-choice.spec.ts`, not yet run live.
  - Round 3 (2026-11-22): the scenario chooses two folders and the type API key together, but the dialog made folders and types exclusive. Chosen folders now also offers the type choice, and `buildScope()` combines both filters (D1): `tests/vitest/export-scope.spec.js` (two folders narrowed to one type, through the real serializer) and `tests/dialogs/ExportDialogs.steps.spec.js` (the scope passed, the type choice shown). Playwright "two chosen folders narrowed to the type API key" in `tests/e2e/workflows/export-choice.spec.ts`, listed, not yet run live; the box stays open for that run.
- [ ] 1.3 Add Export selected to the bulk strip in `SecretList.vue`, and return and show the skipped count from `decryptAllSecrets()`. Verify: Playwright flow select three items, export, the file holds three; vitest that a secret failing to decrypt is counted.
  - Round 2: Export selected on the selection strip opens the dialog on the selection; the skipped count was already returned and shown. Playwright "Export selected writes exactly the three selected items" written, not yet run live. Still owed: a vitest that a secret failing to decrypt is counted (decryptAllSecrets lives in SecretList.vue).
  - Round 2, continued (2026-11-17): the vitest was already there: `tests/dialogs/ExportSkippedSecrets.spec.js` ("decryptAllSecrets() returns the decrypted secrets and the skipped count", and the count reaching both dialogs) and `tests/views/SecretList.methods.more.spec.js` (a blocked row and a failing decrypt both counted). The box stays open for the live Playwright run.

## 2. Restore

- [x] 2.1 In `backupParser.js`, set `sourceRow` and read `typeName`, `typeLabel` and the partial marker. Verify: vitest on a new-format and an old-format fixture.
- [ ] 2.2 In `import.js`, resolve types by D3's order, creating missing custom types through `POST /api/v1/secret-types`, and list created types and fallbacks in the summary. Verify: vitest for each resolution branch, and a Playwright round trip export then restore on a fresh user keeps every type.
  - Round 2: the resolution was already in `import.js` (`resolveRowTypes`); vitest for every branch in `tests/vitest/import-resolveRowTypes.spec.js`. The Playwright round trip is a `test.fixme` in export-choice.spec.ts: not written yet.
  - Round 2, continued (2026-11-17): written. "a backup restored by a fresh user keeps every type" in `tests/e2e/workflows/export-choice.spec.ts`: two fresh non-admin accounts from an empty storage state; the first makes a custom type, a secret of it and a note, and exports an encrypted backup; the second, whose vault has never seen the type, restores it through the import wizard with the passphrase, and the test reads back that the custom type was recreated by name and label and that both secrets carry their type. Lints and lists (`playwright test --list`); not yet run against a live instance, so the box stays open.

## 3. Docs

- [x] 3.1 Update `docs/gdpr.md` and `docs/importing.md` with the scope, field and restore rules. Verify: docs build.
  - Round 2: `docs/gdpr.md` (scope, fields left out, the skipped-secrets wait, types by name) and `docs/importing.md` (restore order) updated; the docs build was not run here.
  - Round 2, continued (2026-11-17): `npm run build` in `docs/` succeeds ("Generated static files"); the broken links it lists are on other pages (ARCHITECTURE, FEATURES, intro), none on these two.

## Acceptance criteria

- An export can be limited to chosen folders, chosen types, or the current selection, and can leave out usernames, additional fields or seeds.
- A backup restored on another user or instance brings back every secret with its type, including custom types.
- Every restored or rejected row names its position in the backup.
- An export that could not include some secrets says how many before it is written.
