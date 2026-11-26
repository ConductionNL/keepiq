---
kind: code
---

# Announce a renewed root to partners, and let admins accept it

## Why

A partner's root fingerprint is pinned when the partner is added. When a Keepiq instance renews its root (`CertificateAuthorityService::renewRoot`), every partner that pinned the old root refuses its certificates with `untrusted_root`. After `partner-share-temporary-refusals`, owners there see their saves stop instead of their shares being suspended, and an admin can re-pin the partner by hand. That still depends on someone noticing the stopped saves and comparing the new fingerprint out of band with the renewing side's administrator.

This change lets the renewing instance announce the renewal, so the receiving instance can check it and ask its admins to accept it, before or soon after the first save stops.

Issue: #1283. This is the second of two changes for it; the first is `partner-share-temporary-refusals`. Delivered together with the first change in PR #1966.

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

- **Renewing side:**
  - `CertificateAuthorityService::renewRoot` has `RootRenewalSignature` sign the new root's DER with the old root key, and `RootRenewalRecorder` stores that signature with the new root (`predecessor_signature` on `keepiq_ca_certs`) in the renewal's transaction, then dispatches `RootRenewedEvent`. When the old root key cannot be read, the root is renewed without a signature and nothing is announced; the renew response says so, and the CA health section tells the admin that partners must re-pin by hand.
  - `FederationRootService::localRootRenewal` gives the announcement for 30 days: `DiscoveryController` publishes it, and `FederatedCertificateService::answer` adds it to lookup answers next to the chain.
  - `RootRenewedListener` queues one notice per partner with inbound sharing on through `RootRenewalNoticeDelivery` (`renewal_notice_attempts` and `renewal_notice_next_at` on `keepiq_federation_partners`), and `RetryFederatedNotificationsJob` sends them with `RootRenewalNoticeSender`, retrying as share notifications are.
- **Receiving side:**
  - `KeepiqSecretFederationProvider::notificationReceived` takes the notice, whose signature `PartnerRootRenewalNoticeSigner` ties to a listed partner, and `FederatedCertificateService::lookup` notices an announcement in a lookup answer.
  - `PartnerRootRenewalService` runs the checks through `PartnerRootRenewalVerifier`, and `PartnerRootRenewalOffers` stores, replaces and withdraws the offer in new columns on `keepiq_federation_partners` (`pending_root_fingerprint`, `pending_root_verified_at`, `pending_root_window_ends_at`, `pending_root_reminded`). The migration is `Version001023Date20261125120000`, nullable columns only.
  - `KeepiqNotifier` gets two subjects, `partner_root_renewal` and `partner_root_renewal_reminder`. `PartnerRootRenewalNotifier` sends them to the members of the `admin` group and of the groups delegated Keepiq's general admin settings, each once, and removes them once the offer is gone. The hourly `PartnerRootRenewalRemindersJob` sends the reminders and expires the offer.
  - Accepting goes through `FederationPartnerChanges::repin` and the re-pin route. The admin settings (`FederationPartnersSection.vue`) show the offer on the partner row, with both fingerprints, the date until which it can be accepted and what accepting replaces.
- **Security:** a valid announcement proves only that whoever holds the old root key approves the new root. Leaving every acceptance to an admin, and closing the window after 30 days, limits what a leaked old root key can do. The signature replaces the out-of-band comparison only inside the window. Roots and fingerprints are public and stored in plain text; no secret value is involved. OpenConnector is not affected.
- **Depends on** `partner-share-temporary-refusals` (the re-pin route and its audit entry), which is archived first.
