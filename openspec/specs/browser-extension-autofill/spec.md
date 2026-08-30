# Browser Extension + Autofill Specification

**Status**: done

**OpenSpec changes:**
- [browser-extension-autofill](../../changes/browser-extension-autofill/)

## Purpose

Keepiq has no way to fill a credential into a login form — every secret must be opened in the web UI and copy-pasted. Autofill via a browser extension is table-stakes for a 2026 password manager and the #1 experiential complaint across the Nextcloud secrets ecosystem (Nextcloud Passwords extension pairing and iframe-autofill failures; Padloc's decline partly from lacking autofill). This feature adds a Manifest V3 WebExtension (Firefox/Chrome/Edge) plus the thin API contract it needs, **without weakening zero-knowledge**: the extension pairs against the Nextcloud session, unlocks the vault in the extension (client-side decrypt), lists URL-matched credentials, autofills login forms including iframes, prompts to save/update on submit, and auto-locks on idle. The server only ever ships encrypted blobs (ADR-003).

## Requirements

### Requirement: Pairing against the Nextcloud session
The system MUST let a browser extension authenticate using a Nextcloud-native credential (app password or OAuth-style flow) tied to the logged-in user, and MUST NOT mint a new long-lived Keepiq credential so revocation stays native to Nextcloud.

#### Scenario: Revocation is native
- GIVEN a paired extension using a Nextcloud app password
- WHEN the user revokes that app password in Nextcloud
- THEN the extension's later API requests MUST be rejected with no Keepiq admin action

### Requirement: In-extension unlock preserves zero-knowledge
The system MUST require the master password to be entered in the extension and used only client-side; the master password, derived key, and plaintext MUST NEVER reach the server.

#### Scenario: Paired but locked cannot decrypt
- GIVEN a paired extension that has not been unlocked
- WHEN it requests secret data
- THEN the server MUST return only encrypted blobs and no value MUST be decryptable until the master password is entered

#### Scenario: Key never persisted
- GIVEN an unlocked extension holding a WebCrypto key
- WHEN it stores configuration
- THEN the key, master password, and plaintext MUST NOT be written to any persistent extension storage, and the key MUST be `extractable: false`

### Requirement: URL-matched listing, decrypt-on-demand
The system MUST offer credentials matching the active tab's origin using the unencrypted `url`/`name` fields, decrypting a value client-side only when selected.

#### Scenario: Candidates for the active origin
- GIVEN an unlocked extension and secrets whose `url`/`name` match the active origin
- WHEN the extension evaluates candidates
- THEN it MUST list them without decrypting values until one is chosen

### Requirement: Autofill including iframes
The system MUST fill detected username/password fields on the page and inside scriptable iframes, and MUST degrade to manual copy for cross-origin frames it cannot script.

#### Scenario: Fill inside an iframe
- GIVEN a login form in a scriptable iframe and a chosen matching credential
- WHEN the user fills it
- THEN the username and password fields inside the iframe MUST be populated

### Requirement: Save/update capture on submit
The system MUST detect credentials on login-form submit and offer to save (new) or update (changed), encrypting client-side and sending only a blob.

#### Scenario: Save a new credential
- GIVEN a submitted login form with credentials not stored for the origin
- WHEN the user confirms save
- THEN the extension MUST encrypt client-side and POST only the encrypted blob

### Requirement: Auto-lock
The system MUST clear the in-memory key on a configurable idle timeout, on browser/OS lock or service-worker termination, and on manual lock, requiring re-unlock before further decryption.

#### Scenario: Idle timeout locks
- GIVEN an unlocked extension left idle beyond its timeout
- WHEN the timeout elapses
- THEN the in-memory key MUST be cleared and the next decrypt attempt MUST require the master password

## User Stories

- As a user, I want the extension to offer my saved credentials on the login page I'm visiting so I don't have to open Keepiq and copy-paste
- As a user, I want autofill to work on login forms embedded in iframes, which the incumbent fails to do
- As a user, I want to be prompted to save or update a credential when I log in, so my vault stays current
- As a security-conscious user, I want unlocking to happen in the extension so the server never sees my master password or plaintext
- As an admin, I want extension access to be revocable from Nextcloud security settings without a Keepiq-specific credential to manage
- As a user, I want the extension to lock itself when I'm away

## Acceptance Criteria

- [ ] The extension pairs via a Nextcloud app password or OAuth-style flow; no new long-lived Keepiq credential is minted
- [ ] Revoking the Nextcloud credential immediately kills the extension's access
- [ ] Unlock happens in the extension; master password, derived key, and plaintext never reach the server
- [ ] A paired-but-locked extension can list unencrypted names/URLs but cannot decrypt any value
- [ ] The key and plaintext are never persisted to extension storage; the key is `extractable: false`
- [ ] URL-matched candidates are offered for the active origin and decrypted only on selection
- [ ] Login forms are autofilled including inside scriptable iframes; non-scriptable frames degrade to manual copy
- [ ] Save/update capture encrypts client-side and sends only a blob
- [ ] The extension auto-locks on idle, browser/OS lock, worker termination, and manual lock
- [ ] The server never returns plaintext to the extension at any step

## Notes

- MV3 WebExtension lives in an in-repo `browser-extension/` workspace, importing the web frontend's shared crypto module to honor ADR-003's dual-implementation invariant.
- Reuses the existing JWT/app-password auth middleware (`JwtAuthService`, `JwtAuthMiddleware`), the discovery document (`DiscoveryController`), and the always-E2E blob-returning secret endpoints.
- Out of scope: Passkey/WebAuthn interception (owned by `passkey-item-type`), mobile apps, TOTP autofill, Bitwarden wire-protocol emulation.
- Related ADRs: ADR-001 (own tables), ADR-003 (always E2E, decrypt location by client type, unencrypted `name`/`url` for matching).
