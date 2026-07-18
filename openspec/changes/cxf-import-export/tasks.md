# Tasks — cxf-import-export

## 0. Scope Note (read first)

Add **file-based** FIDO CXF as a new format on the **existing** import and export pipelines, bidirectionally, entirely client-side — **no new backend routes, no server-side parse/assembly, no CXP/HPKE**. Import reuses mapping preview, folder mapping, duplicate detection, chunked encrypted commit, rejected rows, and summary; export reuses client-side assembly and the plaintext-CSV warning + re-auth gating (a raw CXF file is plaintext). **Depends on `passkey-item-type`** — the passkey mapping targets its canonical schema and `passkey` type. Verify against HEAD before coding: `src/import/parserRegistry.js` (`registerParser`/`getParser`/`listParsers`), the parsers under `src/import/parsers/`, the export writers under `src/export/`, and the `secret-export` re-auth gating.

## 1. CXF format module (bidirectional mapping)

- [x] 1.1 Add a CXF mapping module owning the bidirectional CXF-entity ↔ Doriath-type table (login/passkey/totp/note/api_key/ssh_key/wifi) and strict document-structure validation.
- [x] 1.2 Wire the passkey mapping to the `passkey-item-type` canonical schema (import → canonical JSON in `key` + rp id in `url`; export → CXF passkey entity), and TOTP to the `add-totp-secrets` seed format.
- [x] 1.3 Map Wi-Fi entries to `note` (SSID/security in additional fields, passphrase in `key`) — no new system type in this change.

## 2. CXF import parser

- [x] 2.1 Add a CXF import parser and register it in `src/import/parserRegistry.js` (id `cxf`), producing the normalized row shape every other parser produces so all existing wizard steps apply unchanged; accept both `.cxf` and `.json` by content structure and fail at parse with a format-specific error on a non-CXF file.
- [x] 2.2 Encrypt every sensitive field client-side with the owner's active EncryptionSuite public certificate before commit; require an unlocked vault; never send plaintext to the server.
- [x] 2.3 Route unrepresentable CXF entities and entries missing required fields into the existing rejected-rows list with a reason and source index (unmapped-item report, import side).

## 3. CXF export writer

- [x] 3.1 Add a CXF export writer alongside `src/export/backup.js`/`csv.js` that assembles a standards-conformant CXF document from the client-decrypted vault; never assemble server-side.
- [x] 3.2 Gate CXF export with the existing plaintext-CSV warning + fresh master-password re-authentication (client-side proof; derived key discarded immediately); scope selection reuses the existing whole-vault/folder selection.
- [x] 3.3 Record Doriath values with no CXF home (passkey `counter`/`transports`, custom types) in an export unmapped-item report shown before download (unmapped-item report, export side).
- [x] 3.4 Report the export to the existing export-event endpoint before offering the download; emit `SecretExportedEvent` with mode `cxf` (no secret material). Add the `cxf` mode value.

## 4. Reuse import machinery

- [x] 4.1 Confirm CXF collections map through the existing folder-and-collection-mapping step (choose/create target, nested hierarchy, idempotent, empty-folder suppression), and that duplicate detection (normalized name/url, no decryption of existing secrets, skip/import-as-copy) plus chunked encrypted batch commit apply unchanged — no new logic.

## 5. Frontend wizard wiring

- [x] 5.1 Add CXF as a selectable format in the import wizard and the export dialog; surface the unmapped-item report in both flows via the existing rejected-rows/summary UI.

## 6. Tests

- [x] 6.1 vitest: CXF parser maps each entity type (login/passkey/totp/note/api_key/ssh_key/wifi) to its Doriath type with sensitive fields encrypted client-side; passkey rows match the `passkey-item-type` canonical schema.
- [x] 6.2 vitest: plaintext-never-in-request-body on CXF import; malformed CXF fails at parse and creates nothing; unrepresentable entity lands in the rejected list.
- [x] 6.3 vitest: CXF export assembles locally with no plaintext in any request; the export-event call precedes the download; the unmapped-item report lists non-representable values; the warning + re-auth gate blocks submit until acknowledged + correct password; duplicate detection flags a re-imported CXF file.
- [x] 6.5 vitest: CXF export → CXF import round-trip reproduces core credentials (incl. passkeys) and their folder hierarchy; only documented extensions are lossy.
- [x] 6.6 PHPUnit: `SecretExportedEvent` accepts mode `cxf` and carries no secret names/values/ciphertext.

## 7. Quality Gates

- [x] 7.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes; fix any pre-existing issues in touched files in the same batch.
- [x] 7.2 Frontend lint + vitest pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/cxf-import-export/specs/cxf-import-export/spec.md` on changed methods.
- [x] 7.3 Confirm no new backend route and no new DB table/column/migration; CXF reuses the existing batch-commit and export-event endpoints.

## Acceptance Criteria

- CXF import parses a CXF JSON document client-side, encrypts every sensitive field before persistence, requires an unlocked vault, and reuses the existing mapping preview / folder mapping / duplicate detection / chunked commit / rejected rows / summary.
- CXF entities map to Doriath types bidirectionally (login/passkey/totp/note/api_key/ssh_key/wifi), with passkeys round-tripping via the `passkey-item-type` canonical schema.
- Every unrepresentable item is reported (import rejected rows; export unmapped-item report) — never silently dropped.
- CXF export assembles the document client-side, is gated by an unencrypted-file warning + fresh master-password re-auth, reports before download, and emits `SecretExportedEvent` mode `cxf` with no secret material.
- A CXF export → import round-trip reproduces core credentials and folders; only documented extensions are lossy.
- v1 is file-based only; CXP/HPKE, device pairing, and live handoff are not implemented and are recorded as future work.
- No new backend route and no DB migration were introduced.
