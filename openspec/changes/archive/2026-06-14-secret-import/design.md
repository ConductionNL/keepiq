## Context

Doriath is an encrypted secrets manager with an always-E2E architecture: per the encryption-suites spec, the master password and AES-derived key never leave the browser, the decrypted private key lives only as a non-extractable WebCrypto `CryptoKey` in JS memory, and the server stores/returns only encrypted blobs. The secrets spec defines the Secret entity: `name`, `url`, `type_id`, `folder_id` in plaintext; `key`, `login`, `additional_fields` encrypted with the owner's EncryptionSuite public certificate.

Import is the adoption unlock against the incumbent Nextcloud Passwords app and the major external managers (Bitwarden, KeePass). The constraint that shapes the whole design: **the import file contains every plaintext credential the user owns, and the server must never see it.** That rules out any server-side parsing and forces a browser-side pipeline that ends in the same encrypted-create path the normal UI already uses.

## Goals / Non-Goals

**Goals:**
- Parse generic CSV, Bitwarden JSON, Bitwarden CSV, KeePass 2.x XML, and Nextcloud Passwords JSON backups entirely client-side
- Normalize every format into one intermediate row model so mapping, duplicate detection, and commit are format-agnostic
- Field-mapping preview before anything is persisted; adjustable mapping for generic CSV
- Folder/collection/group hierarchy mapping with create-on-commit
- Client-side duplicate detection with per-row resolution
- Chunked batch commit of client-encrypted payloads
- Row-level rejection handling and a transient summary report

**Non-Goals:**
- KDBX (binary KeePass container) parsing — see D2 for the rationale and the v1 alternative
- Direct API/DB migration from the Nextcloud Passwords app (file-based only in v1; see D3)
- 1Password / LastPass / Chrome export formats (generic CSV mapping covers most of them adequately; dedicated parsers are a follow-up once demand is shown)
- Importing shares, link shares, tags, or attachments from source tools (secrets + folders only in v1)
- Scheduled/automatic sync from another manager (one-shot migration only)
- Importing into another user's vault or an application vault (own user vault only)

## Decisions

### D1: Client-Side Pipeline with a Normalized Intermediate Row Model

The import wizard runs five stages, all in the browser:

```
File pick → Parse (format module) → Map (preview/adjust) → Resolve duplicates → Commit (encrypt + batch POST) → Summary
```

Each format parser (`src/import/parsers/{csv,bitwarden,keepassXml,ncPasswords}.js`) emits the same normalized row shape:

```js
{
  sourceRow: 14,              // 1-based position in the source file, for error reporting
  name: 'GitHub',             // required
  url: 'https://github.com',  // optional
  login: 'octocat',           // optional, sensitive
  key: 'hunter2',             // optional at parse time, required at commit, sensitive
  additionalFields: {…},      // optional, sensitive (notes, TOTP seed string, custom fields)
  typeHint: 'login',          // maps to a system SecretType; defaults to login
  folderPath: ['Work', 'CI'], // source hierarchy as path segments
  errors: []                  // non-empty = rejected row
}
```

**Why:** One intermediate model means mapping, duplicate detection, commit, and the summary report are written once and tested once; adding a new format later is purely a parser module. The plaintext rows live in component-local state, are never written to `localStorage`/`sessionStorage` (same rule as the CryptoKey in the encryption-suites spec), and are discarded when the wizard closes.

**Alternatives considered:**
- Per-format end-to-end flows: rejected — 4 formats × 5 stages of duplicated logic and tests.
- Web Worker for parsing: deferred — files up to a few MB parse in tens of milliseconds on the main thread; a worker adds structured-clone copies of plaintext. Revisit only if profiling shows jank on large vaults.

### D2: KDBX Out of Scope for v1 — KeePass 2.x XML Instead

v1 accepts the KeePass 2.x **XML export** (`File → Export → KeePass XML (2.x)`), not `.kdbx` files. When a user picks a `.kdbx` file, the wizard MUST reject it with a message explaining exactly how to produce the XML export.

**Why out of scope:** KDBX is not an interchange format — it is an encrypted binary container (KDBX4: Argon2 KDF, AES/ChaCha20 outer encryption, an inner header, and per-field protected streams). Supporting it means shipping a second full crypto stack in the browser (e.g. `kdbxweb`, several hundred KB) plus a master-password prompt for a *foreign* vault inside Doriath's UI — a phishing-pattern we do not want to teach users ("enter your KeePass master password into this other app"). The XML export is KeePass's own documented plaintext interchange path, produced inside KeePass where that master password belongs. The cost/benefit flips only if real users demonstrably cannot run the XML export; KDBX support is an explicit candidate follow-up change.

XML caveats handled: the export wraps protected values in `<Value ProtectInMemory="True">` (already plaintext in the export), groups nest recursively (mapped to `folderPath`), and the `History` element of each entry MUST be ignored (only the current value imports).

### D3: Nextcloud Passwords Migration is File-Based (JSON Backup)

The dedicated Passwords-app path imports the app's own **JSON backup export** (Settings → Backup → Export, format "Predefined JSON"), which carries passwords, folders (with hierarchy), custom fields, and notes. The parser maps: `label→name`, `url→url`, `username→login`, `password→key`, `notes` + `customFields→additionalFields`, folder references → `folderPath`.

**Why not a direct server-side migration** (reading the Passwords app's tables or API on the same instance): (1) the Passwords app supports client-side encryption modes the server cannot decrypt; (2) even in server-side mode, a migration endpoint would decrypt every credential **on the server**, violating ADR-003's guarantee that plaintext secret values never exist server-side in Doriath's flows; (3) it couples Doriath to another app's schema. The file-based path keeps the user in control and keeps plaintext strictly in their browser. CSV exports from the Passwords app also work via the generic CSV path, but the JSON path is the recommended one because it preserves folders and custom fields.

### D4: Field-Mapping Preview

After parsing, the wizard shows a mapping table: source field → Doriath field, with the first 5 parsed rows rendered as a preview (key values masked by default, reveal-per-cell on click).

- **Known formats** (Bitwarden, KeePass XML, Passwords): mapping is fixed and shown read-only — preview confirms the parser understood the file.
- **Generic CSV**: the parser auto-detects common headers (`name/title/label`, `url/uri/website`, `username/login/user`, `password/pass/key`, `notes`, `folder/group/grouping`) case-insensitively; every column is user-adjustable via a select per column (target field, "additional field (named)", or "ignore"). Exactly one column must map to `name` and at most one to each of `url`, `login`, `key`. Unmapped columns become named entries in `additionalFields` only if explicitly chosen — default is ignore.

Nothing is persisted server-side at this stage; Back is always available and re-runs nothing.

### D5: Folder / Collection Mapping

Source hierarchies (CSV `folder` column with `/` separators, Bitwarden folders and collection names, KeePass group nesting, Passwords-app folder tree) normalize to `folderPath` segment arrays. The mapping step shows the distinct source folder tree with a target per root node: an existing Doriath folder (NcSelect over the user's folder tree) or "create" (default), plus a global "import everything under one new folder &lt;Import YYYY-MM-DD&gt;" toggle. Sub-paths are always created beneath the chosen target, preserving hierarchy. Folder creation happens server-side at commit time via the existing FolderService — folders for rejected-only rows are not created.

**Why:** Folder names are plaintext organisational metadata in Doriath (secrets spec), so this needs no crypto treatment, but Bitwarden users in particular expect collections to survive migration.

### D6: Duplicate Detection — Client-Side, Plaintext-Metadata Match

A candidate row is a duplicate when an existing vault secret matches on normalized `name` (case-insensitive, trimmed) AND normalized `url` (scheme/trailing-slash-insensitive; both-empty counts as a match). This uses only the plaintext fields the list API already returns, so detection requires no bulk decryption of the existing vault.

Duplicates are shown as a distinct wizard step with per-row resolution: **skip** (default) or **import as copy** (imports with name suffix " (imported)"), plus bulk-apply ("skip all" / "import all"). No overwrite/merge in v1 — overwriting would silently destroy the existing encrypted value and interacts badly with shares and sync-on-update (user-sharing spec); revoke-and-replace stays an explicit manual act.

**Why not value-level comparison:** matching on the decrypted `key` would require decrypting the entire vault into memory at import time. The metadata match catches the real-world case (re-importing the same export) at zero crypto cost; a value-level "reused password" comparison belongs to the planned password-health change.

### D7: Chunked Batch Commit — Encrypt Client-Side, POST Ciphertext

On confirm, the browser processes accepted rows in chunks of 50:

1. For each row: encrypt `key`, `login`, `additionalFields` with the owner's active EncryptionSuite public certificate — calling the **same** `src/crypto` encrypt path used by Create Secret (no new crypto code)
2. `POST /api/v1/secrets/import-batch` with `{ folders: [paths to ensure], items: [{name, url, typeId, folderPath, encryptedKey, encryptedLogin, encryptedAdditionalFields}] }`
3. The server (ImportController → SecretService/FolderService): validates per item (name present, sane lengths, ciphertext envelope shape), ensures folders idempotently, creates secrets, and returns per-index results `{index, status: created|failed, secretId|error}` with HTTP 200 even when some items fail
4. The wizard advances a progress bar per chunk; a failed chunk (network/5xx) is retried once, then its rows join the rejected list with reason "server error"

**Why chunks of 50:** one request per secret is O(n) round-trips (a 2,000-entry KeePass vault = 2,000 POSTs); one giant request risks PHP `post_max_size` and gives no progress. 50 keeps request bodies comfortably under typical limits even with RSA-expanded ciphertexts. Per-index results keep one malformed item from failing its 49 neighbours.

**Why a new endpoint rather than looping the existing create endpoint:** identical security posture (authenticated, owner-scoped, ciphertext-only sensitive fields — the create endpoint accepts the same fields), but transactional per chunk, idempotent folder ensuring, and per-item error reporting. The endpoint carries `#[NoAdminRequired]` and derives the owner exclusively from the session user — no owner field is accepted.

### D8: Rejection Handling and Summary Report

Rows rejected at parse time (missing name, unparseable structure, oversized field), mapping time (row lacks the column mapped to `name`/`key`), or commit time (per-index server failure) accumulate in one rejected list with `sourceRow` and a human-readable reason. A malformed row never aborts the import — parsing is per-row fault-isolated.

The summary step shows: imported, skipped duplicates, rejected (with the per-row reasons), folders created. "Download rejected rows" produces a client-side CSV (Blob URL) so the user can fix and re-import — **the rejected rows contain plaintext credentials, so this file is generated locally and never uploaded; the summary itself is transient UI state, never stored server-side** (storing it would persist a plaintext-adjacent activity record the audit-trail change should own deliberately, not accidentally).

If the file as a whole cannot be parsed (wrong format chosen, truncated file, invalid JSON/XML), the wizard fails fast at the parse step with a format-specific message and nothing is created.

## Risks / Trade-offs

- **[Risk] Large vaults in browser memory** — a 10,000-row import holds all plaintext rows in JS memory during the wizard. Acceptable: the unlocked vault already implies decrypted material in memory per ADR-003; rows are released when the wizard closes. RSA-encrypting 10,000 rows is the slower part (~WebCrypto, milliseconds each) — mitigated by chunked progress UI.
- **[Risk] Generic CSV mis-mapping** — a user maps the password column to `url`, leaking credentials into a plaintext-stored field. Mitigated: the preview renders exactly what will be stored where, `url` values that look high-entropy/non-URL produce a warning badge in the preview, and the mapping step requires explicit confirmation.
- **[Risk] Users expect `.kdbx` to work** — mitigated by detecting the KDBX magic bytes and showing the exact KeePass menu path for the XML export instead of a generic "unsupported file" error (D2).
- **[Trade-off] Metadata-only duplicate detection** (D6) — same-name-different-value rows are flagged as duplicates and skipped by default; the "import as copy" option is the escape hatch. Cheaper and safer than whole-vault decryption.
- **[Trade-off] No import audit event in this change** — FEATURES.md places the audit trail at V1, but it is unbuilt; the export/GDPR change (`secret-export-gdpr`) establishes the typed-event pattern, and an `ImportCompletedEvent` is a one-line addition for the future audit-trail change. Emitting events nobody consumes from two changes at once invites divergence.

## Migration Plan

1. **No database migration** — import writes ordinary secrets/folders rows
2. **Frontend build**: `npm install` (adds `papaparse`), `npm run build`
3. **Routes**: new `import-batch` route registered before the SPA catch-all wildcard
4. **Rollback**: remove the route/controller; already-imported secrets are ordinary secrets and are unaffected
5. **Greenfield**: no existing data to migrate

## Open Questions

- Should the Bitwarden parser import TOTP seeds (`login.totp`) into `additionalFields` as an opaque string? Current decision: yes, as `additionalFields.totp` — Doriath has no TOTP rendering yet (flagged as EXPECTED-GAP in the 2026-06-11 re-evaluation), but discarding the seed would make a later TOTP feature require re-import. The value is encrypted like any other additional field.
- Maximum accepted file size before refusing client-side? Current decision: 20 MB soft cap with a confirm dialog (covers every realistic vault; prevents accidental multi-hundred-MB file picks from freezing the tab).
