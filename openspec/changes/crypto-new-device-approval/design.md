# Design: new device approval

## Context

Code at development `4c214a9d`:

- Web unlock: `src/views/LockScreen.vue` on /lock. `src/store/modules/session.js:134` `unlockWithRawKey(rawUnlockKey)` already unlocks from a raw unlock key: it fetches the active suite, runs `decryptPrivateKeyWithRawKey`, imports the non-extractable `CryptoKey`, and imports the raw key as the AES metadata key used by the offline cache. The passkey unlock uses it (`src/store/modules/passkey.js:236` to `:238`).
- The unlocked session holds only non-extractable keys, so no page can export the raw unlock key without the master password or a PRF passkey. The passkey enrolment re-asks for the master password for the same reason (`src/store/modules/passkey.js:94`).
- `deriveUnlockKeyRaw(password, salt)` (`src/crypto/aes.js:104`) rebuilds the raw unlock key from the master password and the envelope salt.
- HPKE base mode in `src/crypto/hpke.js` (`generateRecipientKeyPair` `:200`, `seal` `:315`, `open` `:342`).
- Extension unlock: `browser-extension/src/lib/vault.js:49` takes a master password only; the change `clients-extension-unlock-lock-and-accounts` adds a raw-key unlock message to the worker.
- Vault-key proofs: `lib/Service/VaultKeyProofService.php:60` to `:74`, the `#[VaultKeyProofRequired]` attribute, and `tests/Unit/Controller/VaultKeyProofAttributesTest.php`.
- Notifications: `lib/Notification/KeepiqNotifier.php` subjects, routed through `NotificationService::SUBJECT_SETTING_MAP`.
- Rate limiting: Nextcloud's `#[UserRateLimit]` attribute (`OCP\AppFramework\Http\Attribute\UserRateLimit`); the app already uses `#[AnonRateLimit]` (`lib/Controller/ApplicationSecretRequestsController.php:88`).

## Goals / Non-Goals

**Goals:**

- A user unlocks a new browser or the extension by approving it from a device where Keepiq is unlocked, without typing the master password on the new device.
- The server relays only ciphertext it cannot open.
- A person who holds only the user's Nextcloud session cannot get the vault opened without the user noticing and approving.
- An administrator-held path for users enrolled in organisation account recovery, with the server still keyless.

**Non-Goals:**

- The Go CLI as a requesting device. It keeps the master password; HPKE in Go is a follow-up.
- The extension as an approving device.
- Remembering the new device. The approval unlocks one session; the next unlock needs the master password, a passkey, or another approval.
- Signing in to Nextcloud itself. Nextcloud owns login; this change is about the vault.

## Decisions

### D1: The new device brings a one-time key

The requesting client generates an X25519 key pair with `generateRecipientKeyPair()`, keeps the private key in memory for the life of the request, and calls `POST /api/v1/device-approvals` with the public key, its client kind (`web` or `extension`) and a device label (browser and OS from the user agent). The server stores the request with the caller's IP address and user agent, a 15 minute expiry, and the hash of a random request secret it returns once. Only the creating client knows that secret, and pickup requires it.

### D2: One verification phrase on both screens

Both devices show a phrase derived from the SHA-256 of the one-time public key: five words from a fixed word list, in the same helper the account recovery change uses. If the server, or anyone in between, swapped the key, the phrases differ and the user denies.

### D3: Approval needs the master password or a passkey

The unlocked web app sees pending requests through `GET /api/v1/device-approvals/pending` (polled while unlocked, and opened from the Nextcloud notification). The dialog shows the device label, the client kind, the IP address, the time and the phrase, with the warning "Only approve a device you are using right now."

To approve, the user confirms their master password (or a PRF passkey). The browser derives the raw unlock key with `deriveUnlockKeyRaw` (or unwraps it with the passkey), checks it against the suite envelope, seals it with HPKE to the request public key (`info` `keepiq-device-approval-v1`, `aad` the request id), and calls `POST /api/v1/device-approvals/{id}/approve` with the sealed key. The route carries `#[VaultKeyProofRequired(binds: ['id', 'sealedUnlockKey'], subject: 'active', purpose: 'approve-device')]`, so an unlocked tab running injected script cannot approve on its own.

Alternative considered: sealing the RSA private key instead of the raw unlock key. Rejected: the web session also needs the raw unlock key as its offline metadata key, and the unlock path from a raw key already exists and is tested.

### D4: Pickup is one-time

The requesting client polls `GET /api/v1/device-approvals/{id}` with its request secret every three seconds until approval, denial or expiry. On approval the response carries the sealed key once; the server then clears it and marks the request `consumed`. The client opens it with its one-time private key, unlocks through `unlockWithRawKey` (web) or the worker's raw-key unlock (extension), and drops the one-time key.

### D5: Deny, expire, limit, notify

`POST /api/v1/device-approvals/{id}/deny` ends a request; the dialog then offers a link to the Nextcloud security settings to end other sessions. A background job marks requests past their expiry as `expired`. `POST /api/v1/device-approvals` is limited with `#[UserRateLimit(limit: 3, period: 3600)]`. Each request raises a Nextcloud notification (`device_approval_requested`). Creation, approval, denial, expiry and pickup are audited with identifiers only. App config `device_approval_enabled` (default true) lets an administrator turn the feature off; when off, creation is refused and the option is hidden.

### D6: The administrator-held path is organisation account recovery

An administrator cannot approve a device on their own: the server has no key to give. For a user enrolled in organisation account recovery, the new device offers "Ask your organisation instead". That files a recovery request (change `crypto-organisation-account-recovery`) carrying the device's one-time key and the purpose `device`. Officers approve it as any recovery request, with the verification phrase and the threshold. The handoff seals the private key to the device's key. For purpose `device` the user's browser unlocks the session with the recovered private key and is not asked to set a new master password; the offline cache stays off for that session because no raw unlock key is present. For users who are not enrolled, the option is not shown.

## Security and zero-knowledge

Stored per request: the user id, the client kind, the device label, the IP address and user agent, the one-time public key, the request secret hash, the status, the times, and, between approval and pickup, the HPKE-sealed unlock key. The server cannot open the sealed key: only the requesting device holds the one-time private key. It never sees the master password, the raw unlock key or the private key (ADR-003).

A stolen Nextcloud session can create a request, but opening the vault still needs the real user to approve it on an unlocked device with their master password or passkey, after seeing the device details and the phrase. The notification and the audit trail make every attempt visible, and the rate limit caps prompt spam.

The raw unlock key exists briefly in the approving page and in the requesting client's memory, never in storage.

## Risks / Trade-offs

- **A user approves without reading.** The dialog leads with the device details and the phrase and needs a password or passkey; the administrator can turn the feature off.
- **The new device must stay open** until approval. A closed tab loses the one-time key; the request then expires unused.
- **Approvals only from the web app.** Users whose only unlocked client is the extension must use the master password on the new device.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. The Playwright flow uses two browser contexts for one seeded user.

## Migration

A new table `keepiq_device_approvals`: id, user_id, client_kind, device_label, requester_ip, requester_agent, request_public_key, request_secret_hash, status (`pending`, `approved`, `denied`, `expired`, `consumed`), created_at, expires_at, decided_at, sealed_unlock_key (TEXT, nullable), with an index on user and status. The `<version>` in `appinfo/info.xml` must bump.
