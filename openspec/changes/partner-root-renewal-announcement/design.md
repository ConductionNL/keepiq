# Design: announce a renewed root to partners, and let admins accept it

## Context

At development `ce51807af`, with `partner-share-temporary-refusals` applied:

- `CertificateAuthorityService::renewRoot` (`:317`) makes a new root key and certificate, stores the new root, and links the old root row to it through `successorId`. The old root's key stays in its row. `CACertificateMapper::findRoot` returns the newest root.
- The discovery document publishes `federation.rootFingerprint` only (`DiscoveryController`, `:188`). `FederationPartnerService::preview` reads it when an admin adds or re-pins a partner.
- `PUT /api/v1/federation/partners/{id}/root` re-pins a partner after password confirmation, and writes a `federation_partner.repinned` audit entry through `FederationPartnerAuditTrail`.
- Incoming OCM notifications reach `KeepiqSecretFederationProvider::notificationReceived`. Every one is addressed to a share.
- `CheckRootCertificateExpiry` notifies the members of the `admin` group through Nextcloud notifications when this instance's own root nears expiry.

The backend lead decided the flow: an announced renewal, a short notification, a check of the old root's signature, an admin who always accepts, a 30-day window with reminders, and the manual re-pin for older partners. Where the proposal lists an open question, this design leaves the choice open and says so.

## D1. The renewing side signs and publishes

`renewRoot` signs the new root certificate with the old root's key, in the same transaction that stores the new root, and keeps the signature with the new root. The discovery document then publishes, under `federation`, the current root's fingerprint as today, plus a renewal announcement: the new root certificate, the previous root certificate, the time of the renewal, and the previous root's signature over the new root. The exact fields and signed bytes are open question 2.

An instance whose root was never renewed publishes no announcement. A second renewal replaces the announcement with one signed by the root it replaces, so a partner two renewals behind cannot follow it and falls back to the manual re-pin.

## D2. The notification

After the renewal commits, the instance sends each partner a signed OCM notification that names only the type, a renewal announcement. It carries no certificate: the receiver reads everything from the discovery document. Delivery is retried as `FederatedNotificationDelivery` retries share notifications. How the notification is addressed (open question 4) and which partners get it (open question 7) are open.

## D3. The receiving side verifies

On an announcement from a host (scope: open question 3), the instance calls `preview` for that partner's base URL and reads the announcement. It accepts the announcement only when all of these hold:

- the previous root's SHA-256 fingerprint equals the partner's pinned fingerprint;
- the previous root's signature over the new root verifies;
- the new root's fingerprint equals the `rootFingerprint` the document publishes;
- the new root is a CA certificate valid now;
- the new fingerprint differs from the pin.

Any other outcome stores nothing and notifies no one; it is logged. The notification itself is never trusted for content.

## D4. A pending renewal

An accepted announcement is stored as the partner's pending renewal: the new fingerprint, when it was verified, and the end of its window. Storage is new nullable columns on `keepiq_federation_partners` with a migration (`ISchemaWrapper`); the fingerprint is public and stored in plain text. A newer announcement replaces a pending one. Re-pinning the partner by any route clears it.

## D5. Admins accept

When a renewal becomes pending, admins get a Nextcloud notification to accept the renewed certificate, which links to the partner in the federation admin settings. Recipients and channel are open question 1. The partner row shows the pending fingerprint next to the pinned one, says the previous root signed it, and offers Accept.

Accepting asks for the password and calls the re-pin route with the pending fingerprint, so the route's checks and audit entry apply unchanged. Before storing, the server reads the discovery document again and repeats D3, so an announcement that changed or disappeared since is refused. Inside the window no out-of-band comparison is asked for: the signature from the pinned root stands in for it.

## D6. The window and the reminders

The window is 30 days. Where it starts is open question 5. A daily background job sends a reminder for each pending renewal not yet accepted, 7, 5, 3 and 1 day before its window ends, each once, as `CheckRootCertificateExpiry` sends each threshold once. When the window ends, the pending renewal is cleared, and the partner row only offers the manual re-pin with its out-of-band comparison.

## D7. Older partners and missed notifications

A partner that publishes no announcement, or whose announcement fails D3, keeps the manual re-pin. Whether a missed notification is caught another way is open question 6; until then, the manual re-pin covers it.

## D8. What a leaked old root key can do

A holder of the old root key can make a valid announcement for a root of their choosing. They still need an admin here to accept it within 30 days, and to serve it from the partner's own discovery URL. A partner can't be moved without an admin's action, and the admin sees which partner and which fingerprint they accept.
