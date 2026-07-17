# Design — passkey-item-type

## Context

Doriath is an always-E2E vault (ADR-003): the server stores only ciphertext, and the master-password-derived AES key plus the decrypted RSA private key live only in the browser as a non-extractable WebCrypto `CryptoKey` (encryption-suites "Session Mechanism"). The `add-totp-secrets` change (archived 2026-07-07) already established the pattern this change follows exactly: add a new *system secret type* whose sensitive value rides in the existing encrypted `key` field, so every existing path (create/read/update, user sharing, link-share snapshot, encrypted/GDPR export, audit) carries the new item with **zero changes** because to all of them it is an opaque ciphertext string.

A WebAuthn passkey is a structured credential (credential id, RP id, user handle, a private key, a signature counter, transports, creation metadata) rather than a single opaque string, but its confidentiality requirement is identical to a password's: the private key material MUST be ciphertext at rest and decryptable only in the browser. This change stores that structure as a canonical JSON object inside the same encrypted `key` blob.

## Goals / Non-Goals

**Goals:**

- Add a `passkey` system secret type so users can store and organise WebAuthn credentials alongside their passwords.
- Define a canonical passkey field schema that aligns 1:1 with the FIDO Credential Exchange Format (CXF) passkey entity, so `cxf-import-export` maps without translation loss on the core fields.
- Present a passkey with its associated site and metadata, keeping the private key material masked and E2E-protected.
- Reuse every existing secret path (sharing, export, audit, search, favicon) unchanged.
- Provide real v1 creation paths: the ordinary secret CRUD API and the existing Bitwarden `fido2Credentials` import mapping.

**Non-Goals:**

- Acting as a WebAuthn authenticator/provider (intercepting `navigator.credentials.create/get`) — needs a browser extension; a **later change**. This change only defines the storage/schema/presentation seam that provider will write through.
- Passkey-based Doriath vault login — unlock stays master-password (ADR-003).
- Any new database column, table, or migration; any new API route.

## Decisions

### D1 — `passkey` is an eighth *system* secret type (UI hint), no new column
Per the `secrets` spec, `SecretType` is "a UI hint only — it drives how the UI labels and presents fields but does not affect server-side validation or the underlying data model." Adding `passkey` to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the fixed namespace, like the other seven — `lib/Repair/SeedSecretTypes.php:62`) is the whole backend change. A new `passkey_*` column would fork every secret path for no benefit and would leak the *existence* of a passkey to the server (a new non-null column is observable), weakening the zero-knowledge posture. Rejected — mirrors `add-totp-secrets` D1/D3.

### D2 — The whole credential is one canonical JSON object stored in the encrypted `key` field
The passkey's structured material is serialized to a canonical JSON object and stored (encrypted) in the existing `key` field. Reusing `key` means user sharing (re-encrypt to recipient public cert), link-share snapshot, encrypted backup export, GDPR export, and audit denormalization carry a passkey with **zero changes**. The canonical schema (all values that are not `rpId` are ciphertext):

| JSON field | Type | Notes | CXF passkey entity |
|------------|------|-------|--------------------|
| `credentialId` | base64url string | WebAuthn credential id | `credentialId` |
| `rpId` | string | Relying-party id (registrable domain, e.g. `example.com`) — **also mirrored into the plaintext `url` field** (D3) | `rpId` |
| `rpName` | string | Human RP name | `rpName` (where present) |
| `userName` | string | Account user name | `userName` |
| `userDisplayName` | string | Account display name | `userDisplayName` |
| `userHandle` | base64url string | WebAuthn user handle | `userHandle` |
| `privateKey` | base64url string | PKCS#8 private key bytes | `key` |
| `algorithm` | integer | COSE algorithm identifier (e.g. `-7` ES256) | carried in `key` encoding |
| `counter` | integer | Signature counter (synced passkeys use `0`) | Doriath extension (not in CXF core) |
| `transports` | string array | e.g. `["internal","hybrid"]` | Doriath extension (not in CXF core) |
| `createdAt` | ISO-8601 string | Creation metadata | Doriath extension |

The core fields map 1:1 to the CXF passkey entity. `counter`, `transports`, and `createdAt` are Doriath extensions beyond CXF core; on CXF export they are lossy (best-effort, dropped if the format has no home), on CXF import they default (`counter: 0`, `transports: []`, `createdAt: now`). This lossiness is documented, not silently guessed.

### D3 — RP id is mirrored into the plaintext `url` field for matching, search, and site display
The RP id (a registrable domain) is stored in the existing plaintext `url` field in addition to the encrypted JSON. This makes passkeys matchable to a site ("which passkey do I have for `example.com`"), searchable, favicon-decorated, and discoverable in Nextcloud unified search — reusing the exact machinery the `secrets` spec already documents for `url`. This carries the same, already-accepted, tradeoff the `secrets` spec calls out: `url` is plaintext and reveals which services a user holds credentials for. No *credential material* (credential id, user handle, private key, counter) is ever placed in a plaintext field.

### D4 — Private key material follows the exact RSA-per-recipient envelope, unchanged
Because the credential lives in `key`, sharing a passkey re-encrypts the `key` blob under the recipient's EncryptionSuite public certificate through the identical WebCrypto path as any secret (ADR-003 "Sharing in E2E"), and export/audit treat it as opaque ciphertext. There is no bespoke passkey crypto — the private key gets exactly the RSA-4096-per-recipient treatment every other secret value gets. Chunking (ADR-003, ~500-byte RSA chunk cap) already applies to `key`/`additional_fields` and covers a serialized passkey JSON object.

### D5 — Creation via API + Bitwarden `fido2Credentials` import; the authenticator seam is deferred
v1 creation paths are (a) the ordinary secret CRUD API with the canonical JSON supplied by the caller, and (b) the existing Bitwarden JSON import parser (`src/import/parsers/bitwarden.js`), which routes each `login.fido2Credentials[]` entry into a `passkey`-typed row, encrypted in the browser like every imported field. A future browser-extension **authenticator/provider** (intercepting WebAuthn ceremonies to mint/assert passkeys) is out of scope; it will write through the *same* secret-create path and canonical schema this change defines — the seam is "a passkey is created by writing a `passkey`-typed secret whose `key` holds the canonical JSON," and nothing about that seam assumes a UI, an import, or an extension.

### D6 — Passkeys are excluded from password-health analysis
A passkey private key is high-entropy machine material; scoring it for strength, flagging reuse, or breach-checking it against HIBP is meaningless and noisy. The health engine (`src/store/modules/health.js`) MUST skip `passkey`-typed secrets, the same guard `add-totp-secrets` D7 added for `totp`. Asserted by test, not a new health feature.

### D7 — Presentation masks the private key; it is never rendered in full
The passkey view shows the associated site (RP id / name), user name / display name, credential id (truncated), transports, and creation date — read-only metadata useful for a human. The `privateKey` (and full `userHandle`/`credentialId`) are secret material and MUST be masked with reveal/copy gated exactly as a password value is; the view MUST NOT print the raw private key inline. A `passkey` secret whose decrypted `key` is not parseable canonical JSON MUST show an explicit "not a valid passkey credential" state and MUST NOT fabricate fields (mirrors `add-totp-secrets` D5's honesty rule).

### Declarative-vs-imperative decision
Imperative, per ADR-001: Doriath owns its own database tables and does **not** use OpenRegister. There is no OR schema, no seed-data register, and no declarative object model — the `passkey` type is a seeded row in Doriath's own `doriath_secret_types` table and the credential is a ciphertext blob in Doriath's own `doriath_secrets.key` column.

## Decisions made under uncertainty

- **CXF passkey entity exact field names.** The FIDO CXF spec reached Proposed Standard (Aug 2025); its passkey entity core fields (`credentialId`, `rpId`, `userName`, `userDisplayName`, `userHandle`, `key`) are stable, but exact casing / optional-field presence may drift with the standard. Decision: pin Doriath's canonical schema to the stable core set and treat `counter`/`transports`/`createdAt` as clearly-labelled Doriath extensions, so a future CXF revision changes only the `cxf-import-export` mapping table, not this change's storage schema.
- **Where the RP id lives.** Chosen: plaintext `url` (enables match/search/favicon/unified-search reuse) accepting the already-documented `url`-is-plaintext tradeoff, rather than a second encrypted-only copy (which would forfeit all that reuse and add nothing — the RP id is a public domain, not a secret). Revisitable if a future privacy tier wants encrypted URLs across all types.
- **Signature counter semantics.** Synced/exported passkeys conventionally carry counter `0`; Doriath stores whatever value is imported and defaults to `0`. Doriath is not an authenticator (D5/non-goals) so it never increments the counter — it is preserved for round-trip fidelity only.
- **Bitwarden `fido2Credentials` shape.** Bitwarden emits passkeys as `login.fido2Credentials[]`; the exact per-entry keys are mapped best-effort into the canonical schema, and any Bitwarden entry that cannot yield at least `credentialId` + `rpId` + `privateKey` is routed to the import rejected-rows list rather than creating a partial passkey.

## Risks / Trade-offs

- **RP id is observable server-side (in `url`).** → Accepted, identical to every other secret's `url`; documented in the `secrets` spec already. No credential material is exposed.
- **CXF extension-field lossiness.** A passkey's `counter`/`transports` may not survive a CXF export round-trip through a third-party manager that ignores extensions. → Documented as best-effort; core credential (usable for authentication) always survives.
- **No authenticator role in v1 may under-deliver user expectation** ("I stored a passkey, why can't Doriath log me in?"). → Mitigated by clear UI copy that v1 is storage/migration; the interception provider is a scoped later change writing through this seam.
- **Malformed/partial credential.** → Honest invalid state (D7); partial imports rejected (D5), never a fabricated passkey.

## Migration / Rollout

- One new seeded system type row via the existing `SeedSecretTypes` repair step (idempotent; deterministic UUID). No data migration — pre-existing secrets are unaffected; users opt in by importing or creating `passkey` secrets.
