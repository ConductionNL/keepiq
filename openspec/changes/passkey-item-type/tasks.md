# Tasks — passkey-item-type

## 0. Scope Note (read first)

Add a `passkey` eighth system secret type and store the WebAuthn credential as canonical CXF-aligned JSON in the existing encrypted `key` field — **no new column, no migration, no server-side passkey logic, no authenticator role**. RP id is mirrored into the plaintext `url`; all credential material stays ciphertext. Presentation, listing/filtering, API/Bitwarden creation, and the password-health exclusion mirror `add-totp-secrets` exactly. Verify against HEAD before coding: `SeedSecretTypes::SYSTEM_TYPES` (`lib/Repair/SeedSecretTypes.php:62`), the Bitwarden parser (`src/import/parsers/bitwarden.js`), the import store totp-expansion pattern (`src/store/modules/import.js`), and the health engine exclusion (`src/store/modules/health.js`).

## 1. Backend — system type seed

- [ ] 1.1 Add `'passkey' => 'Passkey'` to `SeedSecretTypes::SYSTEM_TYPES` (deterministic UUIDv5 under the existing `TYPE_NAMESPACE`, like the other seven); confirm the repair step remains idempotent.
- [ ] 1.2 Confirm no schema/migration change is needed — the credential rides in the existing `key` ciphertext blob; `passkey` is a UI hint per the `secrets` spec.

## 2. Frontend — canonical schema + parser

- [ ] 2.1 Add a passkey credential module: define the canonical JSON schema (`credentialId`, `rpId`, `rpName`, `userName`, `userDisplayName`, `userHandle`, `privateKey`, `algorithm`, `counter`, `transports`, `createdAt`) and a serialize/parse helper.
- [ ] 2.2 On create/update of a `passkey` secret, mirror `rpId` into the plaintext `url` field; keep all credential material in the encrypted `key` JSON only.
- [ ] 2.3 Invalid/unparseable credential → explicit "not a valid passkey credential" state; never fabricate fields (design D7).

## 3. Frontend — presentation, listing, filtering

- [ ] 3.1 Add a passkey presentation component (secret detail, and list row hint) showing associated site (RP id / name), user name / display name, truncated credential id, transports, and creation date; favicon by `url`.
- [ ] 3.2 Mask the private key material (reveal/copy gated as a password value); never render the raw private key inline.
- [ ] 3.3 Add `passkey` to the vault list type filter so users can list only passkeys.

## 4. Import mapping (extends secret-import, additive)

- [ ] 4.1 Extend the Bitwarden JSON parser (`src/import/parsers/bitwarden.js`) to route each `login.fido2Credentials[]` entry into a `passkey`-typed row, encrypted in the browser like every imported field; mirror `rpId` into `url`.
- [ ] 4.2 Reject a Bitwarden entry that cannot yield at least `credentialId` + `rpId` + `privateKey` to the import rejected-rows list (with reason); never create a partial passkey.

## 5. Password-health guard

- [ ] 5.1 Exclude `passkey`-typed secrets from the health engine's strength, reuse, and breach analysis (`src/store/modules/health.js`), alongside the existing `totp` exclusion (design D6).

## 6. Tests

- [ ] 6.1 PHPUnit: `SeedSecretTypes` seeds `passkey` with a stable deterministic UUID and is idempotent; no schema/migration change.
- [ ] 6.2 vitest: canonical passkey JSON serialize/parse round-trips; malformed JSON yields the invalid-credential state, never fabricated fields.
- [ ] 6.3 vitest: creating a `passkey` secret puts the full credential JSON (incl. `privateKey`, `userHandle`, `credentialId`) in the encrypted `key`, mirrors only `rpId` into `url`, and no plaintext credential appears in any request body.
- [ ] 6.4 vitest: Bitwarden `fido2Credentials` entries map to `passkey` rows; an entry missing its private key lands in the rejected list.
- [ ] 6.5 vitest/PHPUnit: a `passkey` secret round-trips create → read → share → export with the credential staying ciphertext server-side; the health engine skips `passkey` secrets.

## 7. Quality Gates

- [ ] 7.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes; fix any pre-existing issues in touched files in the same batch.
- [ ] 7.2 Frontend lint + vitest pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md` on changed methods.
- [ ] 7.3 Confirm no route and no `AuditEventTypes` change; a `passkey` secret uses the existing secret CRUD + audit events unchanged.

## Acceptance Criteria

- `passkey` ("Passkey") is a seeded eighth system type with a stable deterministic UUID; no schema/migration change was introduced.
- A passkey's credential is stored as one canonical JSON object, ciphertext in the existing `key` field; the server cannot distinguish it from any other secret; only `rpId` is mirrored into plaintext `url`.
- The canonical field schema aligns 1:1 with the FIDO CXF passkey entity on the core fields (`credentialId`, `rpId`, `userName`, `userDisplayName`, `userHandle`, `privateKey`); `counter`/`transports`/`createdAt` are documented Doriath extensions.
- Passkeys can be listed/filtered by type and are presented with their associated site and metadata, with the private key masked and never rendered in full.
- An unparseable credential shows an explicit invalid state and never fabricated fields.
- Passkeys are created via the secret CRUD API and via Bitwarden `fido2Credentials` import; a partial Bitwarden entry is rejected, not partially created.
- Passkeys carry through user sharing, link sharing, export, and audit unchanged (credential stays ciphertext) and are excluded from password-health.
- No WebAuthn authenticator/provider behaviour and no passkey vault-login are introduced; the extension-interception seam is left for a later change.
