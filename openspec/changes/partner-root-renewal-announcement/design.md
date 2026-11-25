# Design: announce a renewed root to partners, and let admins accept it

## Context

At development `ce51807af`, with `partner-share-temporary-refusals` applied:

- `CertificateAuthorityService::renewRoot` (`:317`) makes a new root key and certificate, stores the new root, and links the old root row to it through `successorId`. The old root's key stays in its row. `CACertificateMapper::findRoot` returns the newest root.
- The discovery document publishes `federation.rootFingerprint` only (`DiscoveryController`, `:188`). `FederationPartnerService::preview` reads it when an admin adds or re-pins a partner.
- `PUT /api/v1/federation/partners/{id}/root` re-pins a partner after password confirmation, and writes a `federation_partner.repinned` audit entry through `FederationPartnerAuditTrail`.
- Incoming OCM notifications reach `KeepiqSecretFederationProvider::notificationReceived`. Every one is addressed to a share.
- `CheckRootCertificateExpiry` notifies the members of the `admin` group through Nextcloud notifications when this instance's own root nears expiry.

The flow: an announced renewal, a short notification, a check of the old root's signature, an admin who always accepts, a 30-day window from the renewal with reminders, and the manual re-pin for older partners. The announcement travels through all three channels: the discovery document, the certificate lookup answer and the notification.

## D1. The renewing side signs and publishes

`renewRoot` signs the new root certificate with the old root's key, in the same transaction that stores the new root, and keeps the signature with the new root. The discovery document then publishes, under `federation`, the current root's fingerprint as today, plus a renewal announcement: the new root certificate, the previous root certificate, the time of the renewal, and the previous root's signature over the new root. `FederatedCertificateService::answer` (`:123`) adds the same announcement to every lookup answer, next to the chain. Both carry it as `rootRenewal`: `previousRoot` and `newRoot` as PEM, `renewedAt` as an ISO 8601 UTC time, and `signature`, the base64 RSA signature with SHA-256 (`openssl_sign`, `OPENSSL_ALGO_SHA256`) by the previous root's key over the new root's DER, the digest the CA already uses.

The announcement is published until 30 days after the renewal, when the window of D6 ends and no partner can accept it any more; from then on neither the discovery document nor a lookup answer carries it, so a partner stops reading the document again on every lookup. An instance whose root was never renewed publishes no announcement. A second renewal replaces the announcement with one signed by the root it replaces, so a partner two renewals behind cannot follow it and falls back to the manual re-pin.

## D2. The notification

After the renewal commits, the instance sends a signed OCM notification to each partner with inbound sharing on: those are the partners that look up its certificates (`answer` serves no one else) and so pinned its root. The notification has its own notification type and is tied to no share; it says only that this server announced a renewal, and carries no certificate. The receiver reads everything from the discovery document. Right after the commit, a `RootRenewedEvent` queues one notice per partner on its row, due at once; `RetryFederatedNotificationsJob` sends them, so no notice is sent inside the administrator's renewal request. A failed one is retried as `FederatedNotificationDelivery` retries share notifications. A receiver that cannot read the discovery document yet refuses the notice, which Nextcloud answers with 400, so the sender retries it.

## D3. The receiving side verifies

The check starts on a renewal notification from a listed partner, outbound sharing on or off, or when a lookup answer from an outbound partner (`FederatedCertificateService::lookup`, `:191`) carries an announcement whose new root differs from the pin and is not already pending. Either way the instance calls `preview` for that partner's base URL and reads the announcement. It accepts the announcement only when all of these hold:

- the previous root's SHA-256 fingerprint equals the partner's pinned fingerprint;
- the previous root's signature over the new root verifies;
- the new root's fingerprint equals the `rootFingerprint` the document publishes;
- the new root is a CA certificate valid now;
- the new fingerprint differs from the pin;
- the renewal time is not in the future and less than 30 days ago.

Each root is read once, strictly: exactly one `CERTIFICATE` block with only whitespace around it, holding exactly one certificate. Its fingerprint, its public key and its fields all come from that one DER (`CertificatePem`), so no check can read another certificate than the others; anything else is refused.

A notification from a host that is not a listed partner, and any other outcome, stores nothing and notifies no one; it is logged. A failed check also withdraws a renewal already offered for that partner, unless the discovery document could not be fetched, which may pass. A partner with outbound off still gets the offer, because its pin applies again once outbound is turned back on, and the manual re-pin also works for it. The notification and the announcement in a lookup answer only start the check; their content is never trusted.

## D4. A pending renewal

An accepted announcement is stored as the partner's pending renewal: the new fingerprint, when it was verified, and the end of its window, 30 days after the renewal time. Storage is new nullable columns on `keepiq_federation_partners` with a migration (`ISchemaWrapper`); the fingerprint is public and stored in plain text. A newer announcement replaces a pending one. Re-pinning the partner by any route clears it, also a re-pin to the fingerprint already pinned.

## D5. Admins accept

When a renewal becomes pending, admins are notified to accept the renewed certificate, and the notice links to the partner in the federation admin settings. Every member of the `admin` group gets a Nextcloud notification through `KeepiqNotifier`, as `CheckRootCertificateExpiry` notifies them of an expiring root; the reminders go the same way. The partner row shows the pending fingerprint next to the pinned one, says the previous root signed it, and offers "Accept renewed certificate".

Accepting asks for the password and calls the re-pin route with the pending fingerprint, so the route's checks and audit entry apply unchanged. Before storing, the server reads the discovery document again and repeats D3, so an announcement that changed or disappeared since is refused. A newer valid announcement then replaces the offer, with a new notice to the admins; a failed check withdraws it; a document that could not be fetched leaves it. Inside the window no out-of-band comparison is asked for: the signature from the pinned root stands in for it.

## D6. The window and the reminders

The window ends 30 days after the renewal time the partner publishes. An hourly background job sends a reminder for each pending renewal not yet accepted, 7, 5, 3 and 1 day before its window ends, each once, as `CheckRootCertificateExpiry` sends each threshold once; hourly, so cron drift cannot skip the 1-day reminder. The offer itself counts as the reminder its days left fall in. When the window ends, the pending renewal is cleared, and the partner row only offers the manual re-pin with its out-of-band comparison.

## D7. Missed notifications and older partners

A partner that missed the notification finds the announcement in its first certificate lookup after the renewal, which starts the D3 check. A partner that publishes no announcement, or whose announcement fails D3, keeps the manual re-pin.

## D8. What a leaked old root key can do

A holder of the old root key can make a valid announcement for a root of their choosing. They still need an admin here to accept it, and to serve it from the partner's own discovery URL. The renewal time is theirs to set, so the 30 days limit how long a genuine announcement stays acceptable, not what they can do; the admin's acceptance is what stops them. A partner can't be moved without an admin's action, and the admin sees which partner and which fingerprint they accept.
