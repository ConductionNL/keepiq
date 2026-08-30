## 0. Dependency Note (read first)

This change depends on `implement-encryption-suites` (archived — WebCrypto encrypt
path, session store/CryptoKey, lock screen) and on `implement-secrets` /
`implement-secrets-write-ui` (Secret/Folder entities, SecretService,
FolderService, the secret/folder Pinia stores, and the vault UI that hosts the
import entry point). No new crypto code is introduced: import reuses the
existing `src/crypto` RSA encrypt path 1:1.

## 1. Backend — Batch Commit Endpoint

- [ ] 1.1 Create `ImportController` in `lib/Controller/ImportController.php` with `#[NoAdminRequired]` endpoint `batchCreate` (`POST /api/v1/secrets/import-batch`): accepts `{folders: string[][], items: [...]}` where every sensitive field is an encrypted blob; derives owner from the session user only
- [ ] 1.2 Implement per-item validation (name present and ≤ length limit, url/type/folderPath sane, ciphertext envelope shape for encryptedKey/encryptedLogin/encryptedAdditionalFields) returning per-index results `{index, status, secretId|error}` with HTTP 200 on partial failure
- [ ] 1.3 Implement idempotent folder ensuring: resolve/create each folder path segment via `FolderService` (scoped to the session user), caching resolved ids per request; never create folders for paths with zero successful items
- [ ] 1.4 Guard: reject the whole request with 412 when the session user has no `active` EncryptionSuite (consistent with Create Secret behavior)
- [ ] 1.5 Enforce a server-side chunk-size cap (max 100 items per request) and a per-item payload size cap; return 413 above the caps
- [ ] 1.6 Register the route in `appinfo/routes.php` before the SPA catch-all wildcard
- [ ] 1.7 Run the hydra gates locally (route-auth, no-admin-idor, spec-coverage) — the endpoint must be owner-scoped by construction (no owner/user parameter accepted)

## 2. Frontend — Parser Modules

- [ ] 2.1 Add `papaparse` to `package.json`; verify webpack bundles it without config changes
- [ ] 2.2 Create the normalized row model + shared helpers in `src/import/model.js` (row shape, normalization of name/url for duplicate matching, per-row error attachment, 20 MB file-size soft cap check)
- [ ] 2.3 Create `src/import/parsers/csv.js`: papaparse-based, header auto-detection (name/title/label, url/uri/website, username/login/user, password/pass/key, notes, folder/group/grouping — case-insensitive), `/`-separated folder column → folderPath, per-row fault isolation
- [ ] 2.4 Create `src/import/parsers/bitwarden.js`: JSON export (items of type login → rows incl. `login.totp` → `additionalFields.totp`, notes, folder + collection names → folderPath; non-login item types → rejected with reason) and CSV export (delegates to the csv parser with a fixed Bitwarden header mapping)
- [ ] 2.5 Create `src/import/parsers/keepassXml.js`: DOMParser-based KeePass 2.x XML, recursive group traversal → folderPath, String/Value field extraction (Title/URL/UserName/Password/Notes + custom strings → additionalFields), ignore `History` elements, reject on missing KeePass root element
- [ ] 2.6 Create `src/import/parsers/ncPasswords.js`: Nextcloud Passwords JSON backup (label→name, username→login, password→key, notes/customFields→additionalFields, folder id refs resolved to folderPath)
- [ ] 2.7 Implement KDBX detection (magic bytes `0x9AA2D903`) in the file-pick step with the guidance message pointing at KeePass `File → Export → KeePass XML (2.x)`

## 3. Frontend — Pinia Store

- [ ] 3.1 Create `src/store/modules/import.js` (`useImportStore`): state = wizard step, parsed rows, mapping, folder mapping, duplicate resolutions, commit progress, summary; all plaintext state in-memory only (no persistence — mirror the linkShare store's persistence-leak guard)
- [ ] 3.2 Implement `parseFile(file, format)`: dispatch to the parser module, populate rows + rejected rows
- [ ] 3.3 Implement `detectDuplicates()`: fetch the existing vault list (plaintext name/url metadata via the existing secret store), compute matches per the normalized rules
- [ ] 3.4 Implement `commit()`: chunk accepted rows (50/chunk), encrypt sensitive fields per row via the existing `src/crypto` encrypt path + active suite certificate, POST chunks sequentially with one retry per failed chunk, fold per-index failures into the rejected list, build the summary
- [ ] 3.5 Implement `reset()` releasing all plaintext rows; call it on wizard close/destroy

## 4. Frontend — Import Wizard UI

- [ ] 4.1 Create `src/dialogs/ImportWizardDialog.vue` (own file per ADR-004 modal isolation): stepper with file pick + format select → mapping preview → folder mapping → duplicates → commit progress → summary
- [ ] 4.2 Mapping preview step: source→target table, first 5 rows preview, masked sensitive cells with per-cell reveal, per-column NcSelect remapping for generic CSV (enforce exactly-one name, at-most-one url/login/key), warning badge on suspicious url-column values; NcSelect usages carry `inputLabel`
- [ ] 4.3 Folder mapping step: distinct source folder tree, per-root target selector (existing folder or create), "import under one new folder" toggle
- [ ] 4.4 Duplicates step: list with per-row skip / import-as-copy, bulk-apply buttons, default skip
- [ ] 4.5 Commit step: progress bar per chunk, non-dismissable while encrypting/POSTing
- [ ] 4.6 Summary step: imported/skipped/rejected/folders-created counts, rejected-row table with reasons, "Download rejected rows" via client-side Blob CSV
- [ ] 4.7 Add the "Import" entry point to the vault UI (toolbar action next to Create Secret) opening the wizard via the registry modal dispatch; hidden/disabled when the vault is locked
- [ ] 4.8 Lock-screen guard: opening the wizard without a CryptoKey in session redirects to the lock screen

## 5. Internationalization

- [ ] 5.1 Add English strings (wizard steps, format names, KDBX guidance, mapping labels, duplicate resolutions, rejection reasons, summary) to `l10n/en.json` — English source strings as keys
- [ ] 5.2 Add Dutch translations to `l10n/nl.json`

## 6. Unit Tests (PHP)

- [ ] 6.1 `ImportController::batchCreate` tests: per-index results on partial failure; owner derived from session user; 412 without active suite; 413 above chunk/payload caps; rejects plaintext-shaped sensitive fields (envelope validation)
- [ ] 6.2 Folder-ensuring tests: idempotent resolution, nested path creation, no folders created for all-failed paths, scoping to the session user

## 7. Frontend Tests (vitest)

- [ ] 7.1 Parser tests per format with fixture files in `tests/vitest/fixtures/import/`: csv (quoted/escaped/missing-name rows), bitwarden JSON (login + non-login items, totp, collections), bitwarden CSV, keepass XML (nested groups, custom strings, History ignored), ncPasswords JSON (folders, custom fields); KDBX magic-byte rejection
- [ ] 7.2 Duplicate-detection tests: normalization rules (case, trim, url scheme/trailing slash, both-empty url), skip vs import-as-copy resolution effects
- [ ] 7.3 `useImportStore.commit()` tests: chunking at 50, sensitive fields encrypted before POST (plaintext-never-in-request-body assertion, mirroring the linkShare store test), one retry then reject on chunk failure, per-index failures land in rejected list, persistence-leak guard (no localStorage/sessionStorage/IndexedDB writes)
- [ ] 7.4 `ImportWizardDialog` component tests (jsdom harness from `vitest.config.js`): CSV remap updates preview, cannot proceed without exactly-one name mapping, abandon-before-commit creates nothing and resets the store

## 8. E2E (Playwright)

- [ ] 8.1 Import flow e2e: unlock vault → open wizard → upload a small CSV fixture → adjust one column mapping → commit → summary shows counts → imported secret visible in the vault list
- [ ] 8.2 Duplicate flow e2e: re-import the same fixture → duplicates step shown → skip-all → summary reports skipped, vault count unchanged
- [ ] 8.3 Annotate the spec scenarios per gate-19 (`@e2e` references for UI-covered scenarios; `@e2e exclude` with reasons for API-contract-only scenarios such as the chunk-cap 413, covered by PHPUnit)

## 9. Documentation

- [ ] 9.1 Update `docs/FEATURES.md` status for the import rows (V1 → in progress/done)
- [ ] 9.2 Add a user-facing migration guide (`docs/importing.md`): per-tool export instructions (Bitwarden, KeePass XML incl. why not KDBX, Nextcloud Passwords backup), duplicate semantics, what is not imported (shares, tags, attachments)
