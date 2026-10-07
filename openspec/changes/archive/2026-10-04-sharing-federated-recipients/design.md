# Design: federated recipients

## Context

Code at development `4c214a9d`:

- Local sharing is client-side re-encryption (ADR-003): the browser asks `GET /api/v1/shares/recipient-certificate` or `POST /api/v1/shares/recipient-certificates` (`lib/Controller/ShareController.php:309` and `:366`) for a local user's active certificate (`lib/Service/RecipientSecretCopyFactory.php` `certificateFor`), encrypts the value, and the server stores the recipient's copy as a `Secret` owned by that recipient (`lib/Service/RecipientSecretCopyService.php:112` to `:113`) linked by a `ShareTarget`. Sync-on-update re-encrypts for every recipient in the editor's browser (`openspec/specs/user-sharing/spec.md`, requirement "Sync on Update").
- Certificates are issued by the instance's own CA (`lib/Service/CertificateIssuanceService.php:114`); the user's common name is their federated cloud id when available (`lib/Service/EncryptionSuiteProvisioningService.php:331`).
- The public discovery document is `GET /api/v1/app/.well-known/keepiq` (`lib/Controller/DiscoveryController.php:134`, `#[PublicPage]`).
- Keepiq declares Nextcloud 32 to 35 (`appinfo/info.xml`). In Nextcloud's public API, `ICloudFederationProviderManager::addCloudFederationProvider()` and `sendNotification()` exist since 14, `sendCloudShare()` since 29, `IOCMDiscoveryService::discover()` since 28, and `IOCMDiscoveryService::requestRemoteOcmEndpoint()`, `OCP\OCM\Events\LocalOCMDiscoveryEvent` and `OCP\OCM\Events\OCMEndpointRequestEvent` since 33.
- How Nextcloud routes partner traffic (read in server 35, `apps/cloud_federation_api`): every request to `/ocm/<path>` reaches `OCMRequestController::manageOCMRequests()`, which verifies an HTTP signature if one is present (`getIncomingSignedRequest()`), decodes the JSON body, and dispatches `OCMEndpointRequestEvent` with the method, the path below `/ocm/`, the payload and `getRemote()`, the signer's origin, or null for an unsigned request. A listener answers with `setResponse()`; with no answer the caller gets 404. `requestRemoteOcmEndpoint()` reaches `<remote OCM endpoint>/<subpath>` only, never an app route, signs the request and sends the payload as a JSON body for every method. Nextcloud accepts unsigned OCM requests unless `core.enforce_signed_ocm_request` is set.
- No OCM provider is registered in `lib/AppInfo/Application.php`.

## Goals / Non-Goals

**Goals:**

- Share a secret from one Keepiq instance to a named user on another Keepiq instance, with end-to-end encryption between the two browsers.
- Keep the owner's later changes flowing to the remote copy, and make revocation remove it.
- Give both administrators control over which instances their users exchange secrets with.

**Non-Goals:**

- Sharing with someone who has no Keepiq at all. The public link and secret send stay the tools for that.
- Remote recipients editing the shared secret. Remote copies are read-only in this change: the value and the sharing, not the folder the recipient files them in.
- Federated groups, federated team folders and federated link shares.
- Trusting a partner automatically on first contact.

## Decisions

### D1: Partners are an explicit, pinned allowlist

An administrator adds a partner by its base URL. Keepiq reads the partner's discovery document, which gains a `federation` block (enabled flag and the SHA-256 fingerprint of the partner's Keepiq root certificate), and shows the fingerprint for the administrator to confirm out of band before saving. The partner row records the URL, the pinned fingerprint, and whether outbound and inbound are allowed. With no partner, federation is off.

Alternative considered: trust on first use for any instance a user names. Rejected: the pinned root is what lets the browser verify a remote certificate at all (D3).

### D2: Federation needs Nextcloud 33 and runs under OCM

Every server-to-server call in this feature is an OCM request (decision of 2 Oct 2026, keepiq#789). Keepiq advertises the OCM capability `keepiq` through a `LocalOCMDiscoveryEvent` listener (`addCapability('keepiq')`), only while at least one partner exists, so an instance without partners does not announce the feature. The two partner-facing endpoints are not app routes: they are `/ocm/keepiq/recipient-certificate` and `/ocm/keepiq/shares/{id}`, answered by an `OCMEndpointRequestEvent` listener that acts only when `getRequestedCapability()` is `keepiq`.

Outbound, the calling server uses `IOCMDiscoveryService::requestRemoteOcmEndpoint('keepiq', <partner URL>, 'keepiq/...', <payload>, 'post')`, which checks the partner advertises `keepiq`, builds the URL from the partner's OCM discovery, and signs the request. Inbound, the listener takes the verified signer from `getRemote()`. Keepiq refuses an unsigned request (`getRemote()` null) whatever `core.enforce_signed_ocm_request` says, and refuses a signer whose host is not the host of an inbound partner, both with the unknown answer of D3. So a partner with OCM signing disabled cannot federate secrets; the admin section says so.

On Nextcloud 32 the events do not exist: the partner section says federation needs Nextcloud 33 and stays off. Keepiq's declared range is unchanged.

Alternative considered: keeping app routes and signing with `ISignatureManager` directly. Rejected: `requestRemoteOcmEndpoint()` cannot reach an app route, and the OCM path inherits Nextcloud's signing, key rotation and proxy setup.

### D3: Certificate lookup goes server to server and is verified in the browser

The owner types `bob@cloud.partner.example`. Keepiq parses it with `ICloudIdManager`, finds the partner (outbound allowed) by the cloud id's remote, and calls `POST <partner>/ocm/keepiq/recipient-certificate` with the payload `{"cloudId": "bob@cloud.partner.example"}` through `requestRemoteOcmEndpoint()` (D2). The partner's listener answers only when the signer from `getRemote()` is one of its inbound partners, the cloud id belongs to the partner itself, the user exists, allows receiving from other organisations, and holds an active suite. The answer is Bob's active certificate and its CA chain. Every other case gets the same 404 `{"message": "Unknown recipient"}`, so the endpoint cannot tell a non-partner, an opted-out user and a missing user apart.

The owner's browser checks that the chain ends at the pinned partner root and that the certificate's common name is Bob's cloud id, and shows the certificate fingerprint so the owner can compare it with Bob if they want. Only then does it encrypt.

### D4: Delivery over OCM with a pull of the ciphertext

The owner's browser encrypts `key`, `login` and `additionalFields` with Bob's certificate and posts them with the plain `name` and `url` to `POST /api/v1/secrets/{id}/federated-shares` (an ordinary app route, called by the owner's own browser). The server stores an outbound share row and sends an OCM share (resource type `keepiq-secret`, share type `user`) with `sendCloudShare()`, carrying a random shared secret and the owner's cloud id, but not the ciphertext.

Bob's server, in the registered `ICloudFederationProvider::shareReceived()`, checks the sender is an inbound partner, stores a pending inbound row, and notifies Bob. When Bob accepts in "Incoming from other organisations", his server fetches the ciphertext with `requestRemoteOcmEndpoint('keepiq', <sender>, 'keepiq/shares/{id}', {"sharedSecret": ...}, 'post')`, which reaches the sender's `/ocm/keepiq/shares/{id}`. The sender's listener answers only when the signer from `getRemote()` is the host of the share's recipient partner and the shared secret matches the stored hash; anything else gets 404. Bob's server stores the answer as a `Secret` owned by Bob with a read-only flag and the sender's cloud id. Bob decrypts it in his browser with his own key, as any secret.

Alternative considered: putting the ciphertext in the OCM share body. Rejected: a pull lets the receiving server fetch only after acceptance, and lets updates reuse the same path.

### D5: Updates, revocation and expiry

When the owner updates the secret, the browser's sync step also encrypts for each federated recipient, using the certificate fetched again through D3, and posts it to the outbound row; the server sends an OCM notification `SHARE_UPDATED`, and the receiving server pulls again (`notificationReceived()`). Revoking sends `SHARE_UNSHARED` and the receiving server deletes the copy. If the partner is removed or the recipient's certificate no longer verifies, the owner is told and the share is suspended until they revoke or re-share. A failed notification is retried by a background job with backoff and shown to the owner after the last attempt. Nextcloud refuses an OCM notification without a `sharedSecret`, and the sending side keeps only the secret's SHA-256, so every notification carries that hash and the receiving server compares it with the hash of the secret it holds; the receiving server also requires the signer to be the share's partner. The pull still needs the secret itself.

### D7: What the recipient still controls (decision of 4 Oct 2026, keepiq#789)

Read-only means the value and the sharing. Three follow-ups make that precise:

- **Filing.** Bob may move his copy to one of his folders: `PUT /api/v1/secrets/{id}` with only `folderId` (and the offline `baseUpdatedAt`) passes `Secret::assertEditableByHolder()`; any other field is refused with the same read-only answer. The sidebar offers Move and Delete for a copy; edit, archive and share stay hidden. A later pull replaces the value, name and URL but never the folder.
- **Deleting declines.** When Bob moves an accepted copy to the trash (`SecretTrashService::trash()`), or purges a copy whose share is still accepted, `FederatedCopyDeclineService` marks the inbound row declined, detaches the copy, and sends OCM `SHARE_DECLINED` to the owner's instance. That notification carries the shared secret itself: the owner's side keeps only its SHA-256, compares the two, and names the recipient as the signer for Nextcloud's signature check (`getFederationIdFromSharedSecret()`). `FederatedDeclineReceiver` then marks the owner's share `declined`, drops any pending retry, and records `federated_share.recipient_declined`. A declined share is not served, is skipped by the browser's sync, and the owner may share again. The decline is sent once; if it is lost, the owner's next `SHARE_UPDATED` reaches a declined row and Bob's server sends the decline again instead of pulling. Restoring the copy from the trash does not resume the share.
- **Name and URL.** The pull reads the plain name and URL from the source, so a change of only those needs no new ciphertext. `SecretService::update()` calls `FederatedShareService::detailsChanged()` when the name or URL changed and no value field was sent, which sends `SHARE_UPDATED` through the retrying delivery once per live federated recipient. A value change keeps going through the browser's sync (D5), which sends its own notification.

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
