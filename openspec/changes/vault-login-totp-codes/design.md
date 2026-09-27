# Design: one-time codes from a seed kept on a login

## Context

At development `4c214a9d`:

- `src/totp/totp.js` parses an `otpauth://totp/` URI or bare base32 secret and computes an RFC 6238 code with WebCrypto, entirely in memory (`:1-17`, defaults at `:20`).
- `src/components/TotpDisplay.vue` renders a code from a `seed` prop; `src/components/SecretDetailSidebar.vue:213-228` mounts it only when `isTotp` (`:1243-1249`, the secret's type name is `totp`).
- The login's decrypted additional fields render as a plain key/value list at `SecretDetailSidebar.vue:560-580`.
- `src/import/parsers/bitwarden.js:32` maps the CSV column `login_totp` to `additional:totp` and `:70-71` copies `item.login.totp` to `additional.totp` for JSON imports.
- `src/cxf/cxf.js:163-176` turns a CXF `totp` credential into a separate `totp` row with `url: null`, so it can never match a site.
- `browser-extension/src/background/service-worker.js:137-145` fills an OTP field after a login fill using `totpCodeForHost()`, which (`:155-180`) finds a separate `totp`-typed secret matched by host and decrypts its seed in the worker.
- The additional fields are one RSA-encrypted JSON blob (`lib/Db/Secret.php` `additionalFields`), so a new key inside it needs no server change.

## Goals / Non-Goals

**Goals**
- A login that carries a seed shows a live code, in the web app and at fill time in the extension.
- Existing imported seeds light up without a re-import.

**Non-Goals**
- HOTP (counter-based) codes. `totp.js` stays TOTP only.
- Moving existing Authenticator items onto their logins. Both sources keep working; the user can copy a seed into a login by hand.

## Decisions

**D1. The seed is a reserved key inside the encrypted additional fields.** Key `totp`, the name the Bitwarden import already writes. Alternative: a new encrypted column `totp`. Rejected: a new column needs a migration, a new re-encryption path in suite migration and share sync, and a new place for the zero-knowledge guarantee to break, while the additional fields blob already has all three.

**D2. Reserved means handled, not hidden from the user.** `AdditionalFieldsEditor.vue` does not list `totp` as a free field; the dialog shows it as the Authenticator key field. Other keys are unchanged. Lower-case `otp` and `otpauth` keys found on existing items are read as the seed too, and saved back under `totp` on the next edit.

**D3. The login's own seed wins in the extension.** At fill time the worker uses the filled login's seed when present and falls back to `totpCodeForHost()`. Alternative: always the host lookup. Rejected: two logins on one host with different seeds would get the wrong code.

**D4. QR scanning stays in the browser.** The Authenticator key field accepts a QR image from a file picker and decodes it client-side with a small, audited QR decoder added as an npm dependency. No image is uploaded.

## Security and zero-knowledge

The seed is stored only inside the RSA-encrypted `additional_fields` blob, decrypted only in the browser or the extension service worker, and dropped on lock, the same contract as `src/totp/totp.js` and the `extension-totp-autofill` spec ("Honest invalid seed, discard on lock, seed never persisted"). The server sees no new field. An invalid seed shows the existing invalid-seed state and never a guessed code.

## Risks / Trade-offs

- Keeping the password and the second factor in one item puts both factors behind the vault key. That is the user's choice and the reason the Authenticator item stays available; the field help text says it.
- A reserved key could collide with a user's own field named `totp` holding something else. The invalid-seed state makes that visible instead of silent.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture set gains one login for `example.com` with a test seed (a published RFC 6238 test vector, not a real account) so the code row and the extension fill can be exercised.

## Migration

None. No column, no route, no `<version>` bump for data. The frontend reads existing blobs as they are.
