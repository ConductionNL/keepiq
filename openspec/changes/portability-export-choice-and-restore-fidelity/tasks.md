# Tasks: choose what goes into an export, and restore a backup without losing types

## 1. Export

- [ ] 1.1 Extend `serializeVault()` with the combined scope, the field deny list, `typeName` and `typeLabel`, and the partial marker; fix the test fixtures to use UUID type ids. Verify: vitest for each scope, each left-out field, a custom type, and a UUID type id resolving to its name.
- [ ] 1.2 Rebuild the scope and fields part of `ExportDialog.vue` (entire vault, folders, types, selection; fields to leave out). Verify: vitest on `buildScope()`, and a Playwright flow export two folders and one type.
- [ ] 1.3 Add Export selected to the bulk strip in `SecretList.vue`, and return and show the skipped count from `decryptAllSecrets()`. Verify: Playwright flow select three items, export, the file holds three; vitest that a secret failing to decrypt is counted.

## 2. Restore

- [ ] 2.1 In `backupParser.js`, set `sourceRow` and read `typeName`, `typeLabel` and the partial marker. Verify: vitest on a new-format and an old-format fixture.
- [ ] 2.2 In `import.js`, resolve types by D3's order, creating missing custom types through `POST /api/v1/secret-types`, and list created types and fallbacks in the summary. Verify: vitest for each resolution branch, and a Playwright round trip export then restore on a fresh user keeps every type.

## 3. Docs

- [ ] 3.1 Update `docs/gdpr.md` and `docs/importing.md` with the scope, field and restore rules. Verify: docs build.

## Acceptance criteria

- An export can be limited to chosen folders, chosen types, or the current selection, and can leave out usernames, additional fields or seeds.
- A backup restored on another user or instance brings back every secret with its type, including custom types.
- Every restored or rejected row names its position in the backup.
- An export that could not include some secrets says how many before it is written.
