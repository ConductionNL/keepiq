---
kind: code
---

# Proposal: Passkey secret type (WebAuthn credential storage)

## Why

Doriath stores **seven** built-in system secret types today — `login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`, `totp` (verified: `lib/Repair/SeedSecretTypes.php:62`, `SeedSecretTypes::SYSTEM_TYPES`). There is no way to store a **passkey** (a WebAuthn/FIDO2 discoverable credential): no `passkey` type, no credential-material field schema, and nothing in `src/` that presents one. Doriath's own competitive analysis names FIDO2/WebAuthn as a Bitwarden strength three times and calls the gap out explicitly (`docs/FEATURES.md:24` — Bitwarden's "FIDO2"; `docs/FEATURES.md:308` — "FIDO2/WebAuthn"; `docs/FEATURES.md:356` — "Feature gap vs. Bitwarden (browser extension, mobile, FIDO2) | High"). It is an unlisted, unaddressed gap in Doriath's own matrix.

Passkey *storage* is 2026 table stakes for a credential manager:

- **Every serious competitor already ships it.** Bitwarden and Vaultwarden store WebAuthn credentials; the new self-hosted entrant AliasVault ships passkey storage from day one. A password manager that cannot hold a passkey is being left behind as the category rebrands from "password manager" to "credential manager."
- **Adoption is now mainstream.** FIDO Alliance 2026 data puts roughly **75% of consumers** with at least one passkey enabled, and **Microsoft Entra ID defaults new tenants to passkeys from 1 September 2026** — enterprise users will arrive with passkeys expecting somewhere to keep and migrate them.
- **First-mover on the Nextcloud platform.** The incumbent Nextcloud "Passwords" app has **three open passkey issues and no support** (nextcloud/passwords #615 opened 2023, #792 opened May 2026, #353) — no NC-native vault stores passkeys. Doriath can be first.

Critically, a stored passkey fits Doriath's always-E2E architecture with **zero changes to the trust model**, exactly like `totp` did (`archive/2026-07-07-add-totp-secrets`). A passkey's private key material is just another secret value: it is RSA-encrypted with the owner's EncryptionSuite public certificate like a password (ADR-003 — the server only ever sees ciphertext), and everything that already carries a secret — user sharing (re-encrypt to recipient), link sharing, encrypted/GDPR export, and audit — carries a passkey unchanged because to those paths it is an opaque `key` ciphertext blob.

## What Changes

- **Add an eighth system secret type `passkey`** ("Passkey") to the seeded system types (`SeedSecretTypes::SYSTEM_TYPES`, deterministic UUIDv5 like the other seven). Per the `secrets` spec, secret type is a UI hint only — it changes presentation, not the storage model or server validation.
- **Store the whole WebAuthn credential as a canonical JSON object in the existing encrypted `key` field** — credential id, RP id, RP name, user name / display name, user handle, private key (PKCS#8), COSE algorithm, signature counter, transports, and creation metadata. No new column, no migration: the credential is ciphertext in `key` exactly like a password, so a `passkey` secret is indistinguishable from any other secret to the server. The **canonical field schema is defined to align 1:1 with the FIDO Credential Exchange Format (CXF) passkey entity** so the sibling `cxf-import-export` change can map without translation loss on the core fields.
- **Store the RP id in the existing plaintext `url` field** so passkeys are searchable, matchable to a site ("which passkey belongs to `example.com`"), favicon-decorated, and discoverable in Nextcloud unified search — the same plaintext-`url` tradeoff the `secrets` spec already documents. All actual credential material (credential id, user handle, private key, counter) stays encrypted in `key`.
- **List and filter passkeys** by the `passkey` type in the vault list, and **present** a passkey via a type-specific view that shows the associated site (RP id / name), user name, credential id (truncated), transports, and creation date — with the private key material masked and never rendered in full (it is a secret, treated like a password value).
- **Accept passkeys on import via the API and the existing Bitwarden JSON parser.** Bitwarden exports passkeys as a `login.fido2Credentials[]` array; the `secret-import` mapper routes each entry into the `key` of a `passkey`-typed secret, encrypted in the browser like every other imported field (plaintext never sent to the server). This extends the existing import field mapping — no new import surface. Passkeys can also be created through the ordinary secret CRUD API by supplying the canonical JSON.
- **Exclude passkeys from password-health.** A passkey's private key is high-entropy machine material; scoring it for strength, reuse, or breach is meaningless — the health engine MUST skip `passkey`-typed secrets, a guard added exactly as `totp` seeds were excluded.
- **Note the extension-interception seam (explicitly out of scope here).** Acting as a WebAuthn *authenticator/provider* in the browser (intercepting `navigator.credentials.create/get` to register and assert passkeys) requires a browser extension and is a **later change**; this change defines only the storage, schema, presentation, and import/API creation seam that a future provider will write through.

### Non-Goals

- **No WebAuthn authenticator/provider role.** Doriath does not intercept browser WebAuthn ceremonies, does not register new passkeys against relying parties, and does not assert (sign) authentication challenges in v1. It *stores* passkeys other authenticators created / that were exported from another manager.
- **No passkey-based vault login.** Storing a passkey does not turn Doriath's own lock screen into a passkey authenticator; unlock remains master-password (ADR-003).
- **No new column or migration.** The credential lives in the existing `key` ciphertext field; `passkey` is a seeded row in the existing `doriath_secret_types` table.

## Capabilities

### New Capabilities

- `passkey-item-type`: Defines the `passkey` system secret type, the canonical CXF-aligned WebAuthn credential field schema (encrypted in `key`, RP id plaintext in `url`), passkey listing/filtering and site-associated presentation, creation via API and Bitwarden `fido2Credentials` import, the password-health exclusion, and unchanged carry-through of passkeys via sharing/export/audit. The canonical home for the passkey type because it owns the credential schema and presentation contract that `cxf-import-export` depends on.

### Modified Capabilities

_(none — passkey extends the secret-type catalogue additively; like `totp`, it reuses the existing encryption, sharing, export, and audit paths unchanged, so no existing requirement's behavior changes.)_

## Impact

- **Database**: none — no new column, no migration. `passkey` is a seeded row in the existing `doriath_secret_types` table (via the existing `SeedSecretTypes` repair step, `lib/Repair/SeedSecretTypes.php`).
- **Backend**: `lib/Repair/SeedSecretTypes.php` (one entry). No controller, no service logic, no encryption change — the credential is an opaque ciphertext blob to the server.
- **Frontend**: a passkey presentation component (`src/` detail/list view — associated site, user, credential id, transports, created date; private key masked); a list filter for the `passkey` type; the `secret-import` Bitwarden parser (`src/import/parsers/bitwarden.js`) extended to route `login.fido2Credentials[]` into `passkey`-typed rows; the password-health engine (`src/store/modules/health.js`) extended to skip `passkey` secrets.
- **API**: none new — passkeys are created/read/updated through the existing secret CRUD endpoints as ordinary encrypted secrets.
- **Cross-capability**: `secret-import` gains a `fido2Credentials`→passkey mapping (additive); `cxf-import-export` (sibling change) consumes this change's canonical schema as its passkey mapping target; `user-sharing`, `link-sharing`, `secret-export`, and `secret-audit-trail` carry `passkey` secrets unchanged because the credential is just ciphertext in `key`.
- **OpenConnector**: no impact — passkeys are a user-vault credential type; the application-vault secret-store seam is unaffected.
- **Security**: the credential enjoys the same E2E guarantees as any secret value — ciphertext at rest, decrypted only in the browser. The server-side zero-knowledge stance (ADR-003) is unchanged; the server cannot distinguish a `passkey` secret's ciphertext from a password's. No credential material enters audit entries (the existing whitelist already forbids `key`).
