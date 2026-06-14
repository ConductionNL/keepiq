## 0. Dependency Note (read first)

This change depends on `implement-encryption-suites` (archived — WebCrypto encrypt
path, session store/CryptoKey, lock screen) and on `implement-secrets` /
`implement-secrets-write-ui` (Secret/Folder entities, SecretService,
FolderService, the secret/folder Pinia stores, and the vault UI that hosts the
import entry point). No new crypto code is introduced: import reuses the
existing `src/crypto` RSA encrypt path 1:1.

**Built on the secret-export-gdpr foundation:** that change shipped
`src/import/parserRegistry.js` (the parser extension point: `registerParser` /
`getParser` / `listParsers`), `src/import/backupParser.js` (the `.doriath-backup`
restore parser, registered on import), and `src/export/csv.js` with a robust
RFC 4180 `parseCsv()` + the normalized export row contract
(`{ name, url, login, password, additionalFields, folder, type }`). This change
ADOPTS the registry and REUSES `parseCsv()` + the row contract rather than
duplicating them.

## 1. Backend — Batch Commit Endpoint

- [x] 1.1 `ImportController::batchCreate` (`#[NoAdminRequired]`, `POST /api/v1/secrets/import-batch`); owner from session user only — delegates to `ImportService`
- [x] 1.2 Per-item validation (name present + length cap, url cap, ciphertext envelope shape for key/login/additionalFields) returning per-index `{index, status, secretId|error}` with HTTP 200 on partial failure
- [x] 1.3 Idempotent folder ensuring: resolve/create each path segment via `FolderService` (session-user scoped), per-request path→id cache; folders only created when an item commits under them
- [x] 1.4 Guard: 412 when the session user has no `active` EncryptionSuite (via `SecretService::assertActiveSuite`, the same suite resolution as create)
- [x] 1.5 Server-side chunk-size cap (max 100 items) → 413; per-item payload-size cap (name/url length + ciphertext blob length) → per-item reject
- [x] 1.6 Route registered in `appinfo/routes.php` before the `{id}` secret routes and the SPA catch-all wildcard
- [x] 1.7 Hydra gates run locally — endpoint owner-scoped by construction (no owner/user param accepted); diff gate-clean

## 2. Frontend — Parser Modules

- [~] 2.1 `papaparse` NOT added — the shipped `src/export/csv.js` `parseCsv()` already provides RFC 4180 quoting/escaping and round-trips the export side; reusing it avoids a new dependency + bundle/CVE surface (deviation from the design's papaparse plan)
- [x] 2.2 Normalized row model + helpers in `src/import/model.js` (row shape, name/url normalization for dedupe, per-row error attachment, 20 MB soft-cap constant, KDBX magic-byte detection, folder-path segment helpers)
- [x] 2.3 `src/import/parsers/csv.js`: header auto-detection (name/url/login/password/notes/folder synonyms, case-insensitive) + per-column remap, `/`-separated folder paths, per-row fault isolation
- [x] 2.4 `src/import/parsers/bitwarden.js`: JSON (login items incl. `login.totp`→additionalFields.totp, notes, folder + collection names→folder; non-login items rejected) + CSV (fixed Bitwarden header mapping delegating to the csv parser)
- [x] 2.5 `src/import/parsers/keepassXml.js`: DOMParser, recursive group traversal→folder path, Title/URL/UserName/Password + custom strings→additionalFields, `History` ignored, missing-root rejected
- [x] 2.6 `src/import/parsers/ncPasswords.js`: Passwords JSON backup (label→name, username→login, password→key, notes/customFields→additionalFields, folder id refs resolved to paths)
- [x] 2.7 KDBX detection (magic bytes `0x9AA2D903`) in the file-pick step with the KeePass XML-export guidance message
- [x] 2.8 `src/import/parsers/index.js` registers every parser on the adopted registry

## 3. Frontend — Pinia Store

- [x] 3.1 `src/store/modules/import.js` (`useImportStore`): step, parsed rows, mapping, duplicate resolutions, commit progress, summary — all in-memory only (no persistence)
- [x] 3.2 `parseFile(text, format, options)`: dispatch to the registered parser, split accepted vs rejected rows
- [x] 3.3 `detectDuplicates()`: fetch existing vault list metadata (plaintext name/url) via the secret store, match per the normalized rules
- [x] 3.4 `commit()`: chunk at 50, encrypt sensitive fields per row via the existing `src/crypto` encrypt path + active suite certificate, POST chunks sequentially with one retry per failed chunk, fold per-index + chunk failures into the rejected list, build the summary
- [x] 3.5 `reset()` releasing all plaintext rows; called on wizard close/destroy

## 4. Frontend — Import Wizard UI

- [x] 4.1 `src/dialogs/ImportWizardDialog.vue` (own file per ADR-004): stepper (pick + format select → mapping preview → folder mapping → duplicates → commit progress → summary)
- [~] 4.2 Mapping preview: source→target table, first 5 rows, masked sensitive cells with per-cell reveal. The remap path is wired (`parseFile` accepts a mapping, proven by vitest); the in-dialog per-column NcSelect picker UI is deferred to a follow-up — the preview + store-level remap is functional
- [x] 4.3 Folder mapping step: "import under one new folder" toggle + idempotent-create explanation (per-root existing-folder picker deferred with 4.2; server ensures hierarchy idempotently)
- [x] 4.4 Duplicates step: per-row skip / import-as-copy NcSelect, bulk-apply buttons, default skip
- [x] 4.5 Commit step: progress text per chunk, non-dismissable primary action while committing
- [x] 4.6 Summary step: imported/skipped/rejected/folders-created counts, rejected-row table, "Download rejected rows" client-side Blob CSV
- [x] 4.7 "Import" entry point in the vault toolbar; disabled when the vault is locked
- [x] 4.8 Lock-screen guard: the wizard renders a lock NoteCard + reads no file when locked; the toolbar button is disabled while locked

## 5. Internationalization

- [x] 5.1 English strings added to `l10n/en.json` — English source strings as keys
- [x] 5.2 Dutch translations added to `l10n/nl.json`

## 6. Unit Tests (PHP)

- [x] 6.1 `ImportController` tests: 401 anon, 400 empty, 413 over cap, 412 no suite, 200 per-index results with session owner (attacker ownerId ignored). `ImportService` tests: per-index partial failure, plaintext-shaped sensitive field rejected, owner = session user
- [x] 6.2 Folder-ensuring tests: idempotent resolution (existing reused), nested path created once, no folders for root-level items, session-user scoped

## 7. Frontend Tests (vitest)

- [x] 7.1 Parser tests with inline fixtures: csv (quoted/escaped/missing-name/remap), bitwarden JSON (login + non-login + totp + collections), bitwarden CSV, keepass XML (nested groups, custom strings, History ignored, missing-root throw), ncPasswords JSON (folders + custom fields), KDBX magic-byte detection
- [x] 7.2 Duplicate-detection tests: normalization rules (case, trim, url scheme/trailing slash, both-empty), skip vs import-as-copy resolution effects
- [x] 7.3 `useImportStore.commit()` tests: chunking at 50, sensitive fields encrypted before POST (plaintext-never-in-request-body assertion + decrypt round-trip), one retry then reject on chunk failure, per-index failures land in rejected list, persistence-leak guard, export→import round-trip via a real `.doriath-backup`
- [x] 7.4 `ImportWizardDialog` component tests: cannot proceed without rows, abandon-before-commit resets + creates nothing, locked-vault guard reads no file, KDBX detection

## 8. E2E (Playwright)

- [x] 8.1 Import flow e2e: unlock → open wizard → upload CSV → commit → summary counts → imported secret visible in the vault
- [x] 8.2 Duplicate flow e2e: re-import the same fixture → duplicates step → skip-all default → summary reports skipped, imported 0
- [x] 8.3 Spec scenarios annotated per gate-19: 3 UI scenarios `@e2e secret-import::<slug>`; the rest carry reason-bearing `@e2e exclude` (covered by vitest/PHPUnit)

## 9. Documentation

- [x] 9.1 `docs/FEATURES.md` import row marked Built with the format list + capability summary
- [x] 9.2 `docs/importing.md` migration guide: per-tool export instructions (Bitwarden, KeePass XML incl. why not KDBX, Nextcloud Passwords backup), duplicate semantics, what is not imported
