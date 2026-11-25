---
kind: code
---

# Announce a renewed root to partners, and let admins accept it

## Why

A partner's root fingerprint is pinned when the partner is added. When a Keepiq instance renews its root (`CertificateAuthorityService::renewRoot`), every partner that pinned the old root refuses its certificates with `untrusted_root`. After `partner-share-temporary-refusals`, owners there see their saves stop instead of their shares being suspended, and an admin can re-pin the partner by hand. That still depends on someone noticing the stopped saves and comparing the new fingerprint out of band with the renewing side's administrator.

This change lets the renewing instance announce the renewal, so the receiving instance can check it and ask its admins to accept it, before or soon after the first save stops.

Issue: #1283. This is the second of two changes for it; the first is `partner-share-temporary-refusals`. The backend lead decided the flow on the issue's question page. The notice's recipients, the signature format and which partners may announce were settled afterwards, on PR #1966.

## What Changes

- **Renewing announces.** When an instance renews its root, the old root signs the new root. The instance carries the announcement (both roots, the renewal time and that signature) through three channels: its discovery document, its certificate lookup answers next to the chain, and a signed OCM notification to each partner with inbound sharing on. The notification is its own OCM notification type, tied to no share, and only says that this server announced a renewal.
- **Receiving checks.** On that notification, or on a certificate lookup answer that carries an announcement, the receiving instance fetches the partner's discovery document the way it does when a partner is added, and checks that the previous root is the pinned one and that it signed the new root. A partner that missed the notification is caught by the first lookup after the renewal.
- **Admins accept.** Only when that checks out are admins notified to accept the renewed certificate. Accepting needs the admin's password and stores the new pin through the re-pin of `partner-share-temporary-refusals`, with its audit entry. The server never moves a pin by itself.
- **A 30-day window.** The old root's signature is accepted for 30 days after the renewal. When no admin has accepted, reminders go out 7, 5, 3 and 1 day before the window ends. After it, only the manual re-pin with an out-of-band comparison remains.
- **Older partners** that send no announcement keep the manual re-pin.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `federated-sharing`: instances announce a renewed root to their partners; a receiving instance verifies the announcement and offers it to administrators, who accept it with a re-pin; reminders before the window ends.

## Impact

- **Renewing side:** `CertificateAuthorityService::renewRoot` signs the new root with the old root key, and the result is kept. `DiscoveryController` publishes the renewal, and `FederatedCertificateService::answer` adds it to lookup answers next to the chain. A new OCM notification type is sent to partners with inbound sharing on, with retries.
- **Receiving side:**
  - `KeepiqSecretFederationProvider::notificationReceived` takes the notification, and `FederatedCertificateService::lookup` notices an announcement in a lookup answer.
  - `FederationPartnerService` verifies it and stores a pending renewal per partner, which needs new columns on `keepiq_federation_partners` or a new table, with a migration.
  - `KeepiqNotifier` gets a new subject for the members of the `admin` group, and a background job sends the reminders.
  - The admin settings show a pending renewal on the partner row.
- **Security:** a valid announcement proves only that whoever holds the old root key approves the new root. Leaving every acceptance to an admin, and closing the window after 30 days, limits what a leaked old root key can do. The signature replaces the out-of-band comparison only inside the window. Roots and fingerprints are public and stored in plain text; no secret value is involved. OpenConnector is not affected.
- **Depends on** `partner-share-temporary-refusals` (the re-pin route and its audit entry), which is archived first.
