---
kind: code
---

# Announce a renewed root to partners, and let admins accept it

## Why

A partner's root fingerprint is pinned when the partner is added. When a Keepiq instance renews its root (`CertificateAuthorityService::renewRoot`), every partner that pinned the old root refuses its certificates with `untrusted_root`. After `partner-share-temporary-refusals`, owners there see their saves stop instead of their shares being suspended, and an admin can re-pin the partner by hand. That still depends on someone noticing the stopped saves and comparing the new fingerprint out of band with the renewing side's administrator.

This change lets the renewing instance announce the renewal, so the receiving instance can check it and ask its admins to accept it, before or soon after the first save stops.

Issue: #1283. This is the second of two changes for it; the first is `partner-share-temporary-refusals`. The backend lead decided the flow on the issue's question page.

## What Changes

- **Renewing announces.** When an instance renews its root, the old root signs the new root, and the instance publishes both roots and that signature in its discovery document. It sends each partner a signed OCM notification that only says a renewal was announced.
- **Receiving checks.** On that notification, the receiving instance fetches the partner's discovery document the way it does when a partner is added, and checks that the previous root is the pinned one and that it signed the new root.
- **Admins accept.** Only when that checks out are admins notified to accept the renewed certificate. Accepting needs the admin's password and stores the new pin through the re-pin of `partner-share-temporary-refusals`, with its audit entry. The server never moves a pin by itself.
- **A 30-day window.** The old root's signature is accepted for 30 days after the renewal. When no admin has accepted, reminders go out 7, 5, 3 and 1 day before the window ends. After it, only the manual re-pin with an out-of-band comparison remains.
- **Older partners** that send no announcement keep the manual re-pin.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `federated-sharing`: instances announce a renewed root to their partners; a receiving instance verifies the announcement and offers it to administrators, who accept it with a re-pin; reminders before the window ends.

## Impact

- **Renewing side:** `CertificateAuthorityService::renewRoot` signs the new root with the old root key, and the result is kept. `DiscoveryController` publishes the renewal. A new OCM notification is sent to partners, with retries.
- **Receiving side:**
  - `KeepiqSecretFederationProvider::notificationReceived`, or a new receiver, takes the announcement.
  - `FederationPartnerService` verifies it and stores a pending renewal per partner, which needs new columns on `keepiq_federation_partners` or a new table, with a migration.
  - `KeepiqNotifier` gets a new admin subject, and a background job sends the reminders.
  - The admin settings show a pending renewal on the partner row.
- **Security:** a valid announcement proves only that whoever holds the old root key approves the new root. Leaving every acceptance to an admin, and closing the window after 30 days, limits what a leaked old root key can do. The signature replaces the out-of-band comparison only inside the window. Roots and fingerprints are public and stored in plain text; no secret value is involved. OpenConnector is not affected.
- **Depends on** `partner-share-temporary-refusals` (the re-pin route and its audit entry), which is archived first.

## Open questions

These are not decided, and the design does not assume an answer. Each needs a backend or frontend lead.

1. **Reminder recipients and channel.** Who receives the "accept renewed certificate" notice and the reminders, and how? For example: every member of the Nextcloud `admin` group through Nextcloud notifications, as `CheckRootCertificateExpiry` does for an expiring root, or also by email.
2. **Signature format.** What the announcement in the discovery document looks like exactly: which fields, the signature algorithm, and how the signed bytes are defined (for example the new root's DER).
3. **A host that isn't an outbound partner.** What happens when an announcement arrives from a host that is no partner, or one with outbound sharing off, or only inbound.
4. **Addressing the notification.** Nextcloud's OCM notifications are addressed to a share (`providerId`, share type `keepiq-secret`), and a renewal belongs to no share. Should the announcement ride on an existing share, use its own OCM resource type, or use a Keepiq endpoint of its own under `/ocm/keepiq/`?
5. **Where the window starts.** Does the 30 days count from the renewal time the partner publishes, or from when this instance first received and verified the announcement? A partner's own time can be set by whoever holds the old root key.
6. **A missed notification.** Should a partner that misses the notification (down, or the notification lost after its retries) still find the announcement some other way, for example a daily check of partners' discovery documents, or when a lookup answers `untrusted_root`? Or is the manual re-pin enough then?
7. **Which partners are told.** Should the renewing side notify every partner, or only those that may look up and deliver here (inbound on), since only those use its root?
