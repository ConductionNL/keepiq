# Design: federated recipients

## Context

Code at development `4c214a9d`:

- Local sharing is client-side re-encryption (ADR-003): the browser asks `GET /api/v1/shares/recipient-certificate` or `POST /api/v1/shares/recipient-certificates` (`lib/Controller/ShareController.php:309` and `:366`) for a local user's active certificate (`lib/Service/RecipientSecretCopyFactory.php` `certificateFor`), encrypts the value, and the server stores the recipient's copy as a `Secret` owned by that recipient (`lib/Service/RecipientSecretCopyService.php:112` to `:113`) linked by a `ShareTarget`. Sync-on-update re-encrypts for every recipient in the editor's browser (`openspec/specs/user-sharing/spec.md`, requirement "Sync on Update").
- Certificates are issued by the instance's own CA (`lib/Service/CertificateIssuanceService.php:114`); the user's common name is their federated cloud id when available (`lib/Service/EncryptionSuiteProvisioningService.php:331`).
- The public discovery document is `GET /api/v1/app/.well-known/keepiq` (`lib/Controller/DiscoveryController.php:134`, `#[PublicPage]`).
- Keepiq declares Nextcloud 32 to 34 (`appinfo/info.xml:107`). In Nextcloud's public API, `ICloudFederationProviderManager::addCloudFederationProvider()` and `sendNotification()` exist since 14, `sendCloudShare()` since 29, `IOCMDiscoveryService::discover()` since 28, and `IOCMDiscoveryService::getIncomingSignedRequest()` and `requestRemoteOcmEndpoint()` since 33.
- No OCM provider is registered in `lib/AppInfo/Application.php`.

## Goals / Non-Goals

**Goals:**

- Share a secret from one Keepiq instance to a named user on another Keepiq instance, with end-to-end encryption between the two browsers.
- Keep the owner's later changes flowing to the remote copy, and make revocation remove it.
- Give both administrators control over which instances their users exchange secrets with.

**Non-Goals:**

- Sharing with someone who has no Keepiq at all. The public link and secret send stay the tools for that.
- Remote recipients editing the shared secret. Remote copies are read-only in this change.
- Federated groups, federated team folders and federated link shares.
- Trusting a partner automatically on first contact.

## Decisions

### D1: Partners are an explicit, pinned allowlist

An administrator adds a partner by its base URL. Keepiq reads the partner's discovery document, which gains a `federation` block (enabled flag and the SHA-256 fingerprint of the partner's Keepiq root certificate), and shows the fingerprint for the administrator to confirm out of band before saving. The partner row records the URL, the pinned fingerprint, and whether outbound and inbound are allowed. With no partner, federation is off.

Alternative considered: trust on first use for any instance a user names. Rejected: the pinned root is what lets the browser verify a remote certificate at all (D3).

### D2: Federation needs Nextcloud 33

Every server-to-server call in this feature is a signed OCM request: outbound through `IOCMDiscoveryService::requestRemoteOcmEndpoint()`, inbound checked with `getIncomingSignedRequest()`. Both exist from Nextcloud 33. On Nextcloud 32 the partner section says federation needs Nextcloud 33 and stays off. Keepiq's declared range is unchanged.

### D3: Certificate lookup goes server to server and is verified in the browser

The owner types `bob@cloud.partner.example`. Keepiq parses it with `ICloudIdManager`, finds the partner (outbound allowed), and calls the partner's new route `GET /api/v1/federation/recipient-certificate?cloudId=<id>` as a signed OCM request. The partner answers only callers on its own inbound allowlist, and only for users whose Keepiq setting allows receiving from other organisations; any other case answers like an unknown user. The answer is Bob's active certificate and its CA chain.

The owner's browser checks that the chain ends at the pinned partner root and that the certificate's common name is Bob's cloud id, and shows the certificate fingerprint so the owner can compare it with Bob if they want. Only then does it encrypt.

### D4: Delivery over OCM with a pull of the ciphertext

The owner's browser encrypts `key`, `login` and `additionalFields` with Bob's certificate and posts them with the plain `name` and `url` to `POST /api/v1/secrets/{id}/federated-shares`. The server stores an outbound share row and sends an OCM share (resource type `keepiq-secret`, share type `user`) with `sendCloudShare()`, carrying a random shared secret and the owner's cloud id, but not the ciphertext.

Bob's server, in the registered `ICloudFederationProvider::shareReceived()`, checks the sender is an inbound partner, stores a pending inbound row, and notifies Bob. When Bob accepts in "Incoming from other organisations", his server fetches the ciphertext from the sender's `GET /api/v1/federation/shares/{id}` with the shared secret in a signed request, and stores it as a `Secret` owned by Bob with a read-only flag and the sender's cloud id. Bob decrypts it in his browser with his own key, as any secret.

Alternative considered: putting the ciphertext in the OCM share body. Rejected: a pull lets the receiving server fetch only after acceptance, and lets updates reuse the same route.

### D5: Updates, revocation and expiry

When the owner updates the secret, the browser's sync step also encrypts for each federated recipient, using the certificate fetched again through D3, and posts it to the outbound row; the server sends an OCM notification `SHARE_UPDATED`, and the receiving server pulls again (`notificationReceived()`). Revoking sends `SHARE_UNSHARED` and the receiving server deletes the copy. If the partner is removed or the recipient's certificate no longer verifies, the owner is told and the share is suspended until they revoke or re-share. A failed notification is retried by a background job with backoff and shown to the owner after the last attempt.

### D6: Policy on both sides

Instance level: the partner allowlist (D1). User level: a personal setting "Receive secrets from other organisations" (default off) that D3 checks. Owner side: the share dialog offers federated recipients only when at least one outbound partner exists.

## Security and zero-knowledge

Between instances travel only: OCM share metadata (cloud ids, share id, shared secret, resource type), certificates, and ciphertext encrypted in the owner's browser for the recipient's certificate. Neither server can decrypt a value; the owner's server never holds the recipient's private key and the recipient's server never holds the owner's (ADR-003).

Stored on the sending side: the outbound share row with the recipient cloud id, partner id, recipient certificate fingerprint, status, the hashed shared secret, and the latest ciphertext for the recipient (needed for the pull). Stored on the receiving side: the inbound row with sender cloud id, partner id, remote share id, the shared secret encrypted with `ICrypto` (the server must present it unattended), status, and the local copy's id. The `name` and `url` of a shared secret are plain on both sides, as they are for local shares.

What a partner could still do: a malicious partner server could issue a certificate for its own user that the owner's browser accepts, because the owner trusts the partner's root by design. The fingerprint display and the administrator's explicit allowlist are the controls; the owner shares only with instances their administrator approved.

## Risks / Trade-offs

- **Two administrators must act before anyone can share.** Deliberate: federation of secrets should never be on by accident.
- **Nextcloud 32 instances cannot federate.** Stated in the admin section.
- **Remote copies lag if a notification fails.** Retries and an owner-visible failure state cover it; the remote copy is never partially updated because the pull replaces it whole.
- **A recipient cannot edit.** Remote editing would need the recipient's browser to encrypt for the owner and every other recipient across instances; a later change.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. The integration test runs two Nextcloud 33 containers on one Docker network, each with Keepiq and a seeded user, set up by the test itself; no fixture data is committed.

## Migration

New tables through a new migration step:

- `keepiq_federation_partners`: id, base_url, root_fingerprint, allow_outbound, allow_inbound, added_by, added_at.
- `keepiq_federated_shares` (outbound): id, source_secret_id, owner_id, recipient_cloud_id, partner_id, recipient_cert_fingerprint, key, login, additional_fields (ciphertext for the recipient), shared_secret_hash, status, created_at, updated_at.
- `keepiq_federated_inbound`: id, recipient_uid, sender_cloud_id, partner_id, remote_share_id, shared_secret_enc, secret_id, status (`pending`, `accepted`, `declined`, `revoked`), received_at.

`secrets` gains `federated_source` (nullable `STRING(255)`, the sender cloud id) and `read_only` (boolean, default false). The `<version>` in `appinfo/info.xml` must bump.
