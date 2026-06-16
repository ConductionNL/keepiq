## Why

Doriath can store and encrypt secrets (implement-secrets) but has no way to share them with external parties who lack Nextcloud accounts. Link sharing is an MVP-tier feature that enables secure one-time or limited-use handoff of secret values to anyone with a browser and a password. Without it, users must resort to insecure channels (email, chat) to transmit credentials to external collaborators.

## What Changes

- Implement LinkShare entity with encrypted point-in-time snapshot of a secret, stored in `doriath_link_shares` table
- Implement link share creation flow: browser decrypts secret, generates link password, derives AES-256 key via Argon2id (WASM), encrypts snapshot, POSTs encrypted blob; server generates token (128+ bits via `random_bytes()`), stores blob, returns link URL
- Implement public link access page (`/share/link/:token`): visitor enters password, browser derives AES key via Argon2id, decrypts snapshot client-side; server increments usage count on successful decryption confirmation
- Implement usage limit enforcement: minimum 1, maximum 10, default 1; no unlimited option; auto-delete link share when usage limit reached
- Implement brute-force protection: 5 consecutive failed password attempts permanently delete the link share
- Implement manual revocation by secret owner
- Implement link share management UI: creation dialog, active link list per secret, copy link/password controls
- Add database migration for `doriath_link_shares` table via ISchemaWrapper
- Add Argon2id WASM dependency (e.g., `argon2-browser`) for client-side KDF

## Capabilities

### New Capabilities
- `link-sharing`: Password-protected share links for external parties — creation with Argon2id-encrypted snapshot, public access page with client-side decryption, usage limits (1-10), brute-force protection (5 attempts), auto-deletion, manual revocation, and management UI

### Modified Capabilities
_(none — link sharing is a new capability that does not change existing secret or encryption suite requirements)_

## Impact

- **Database**: One new table (`doriath_link_shares`) via ISchemaWrapper migration with unique index on token
- **Backend**: New entity (LinkShare), mapper (LinkShareMapper), service (LinkShareService), controllers (LinkShareController for authenticated CRUD, LinkShareAccessController for public access), and a development seed repair step
- **Frontend**: New Pinia store (useLinkShareStore), Vue components (LinkShareAccess public page, LinkShareCreateDialog, LinkShareList), Argon2id WASM integration, Vue Router public route (`/share/link/:token` — no lock screen guard)
- **API**: Authenticated endpoints for link share CRUD under `/api/v1/secrets/{secretId}/link-shares/`, public endpoint for link access under `/api/v1/public/link-shares/{token}`
- **Dependencies**: Depends on implement-encryption-suites (EncryptionSuite, crypto services, session store) and implement-secrets (Secret entity and service). New npm dependency: `argon2-browser` (or equivalent Argon2id WASM library)
- **Security**: Snapshot encrypted with Argon2id-derived AES key; password never sent to or stored on server; brute-force protection auto-deletes after 5 failed attempts; usage limit prevents unlimited access; server stores only encrypted blob and cannot decrypt without password
- **Cross-app**: No direct impact on OpenConnector — link sharing is for external human recipients, not application-to-application flows
