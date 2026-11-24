---
kind: code
---

# Stop the save on partner refusals that may be temporary

## Why

When an owner saves a secret that is shared to a partner organisation, the browser looks up each partner recipient's certificate again. Both clients, the web app and the browser extension, suspend the partner share on every refusal they read as a verdict about the recipient, and a suspended share stays suspended until the owner revokes it or shares again. Three of those refusals come from something temporary, so a routine action ends every share to that partner for good, one item at a time, as each is next saved:

- **A partner renews its root.** The pin is stored once, when the partner is added, and nothing updates it. After `CertificateAuthorityService::renewRoot` on the partner's side, the browser answers `untrusted_root` and suspends.
- **An admin turns outbound sharing off for a partner.** The lookup answers `not_a_partner`, the same as for a removed partner, and both clients suspend.
- **The owner's clock is wrong.** The web app suspends on `not_valid_now`; the extension already stops the save with `partner_clock`.

Issue: #1283 (merges #1284). This is the first of two changes for it. The second adds announced root renewals: the old root signs the new one, a partner is told by a notification, and an admin accepts the renewal once the signature checks out.

## What Changes

- **Both clients stop the save on `untrusted_root`** with the unreachable-partner dialog instead of suspending, worded for a root change. The owner can try again or save without that partner, which suspends as it does today.
- **A partner with outbound sharing off answers `outbound_disabled`** with 403, on the certificate lookup, the batch lookup and when creating a new share. A removed partner keeps `not_a_partner`. Both clients stop the save on `outbound_disabled` with the same dialog, and the share form shows its own message for it.
- **The web app stops the save on `not_valid_now`** with its own error asking the owner to check the device clock, as the extension does with `partner_clock`. It offers no saving without them.
- **Administrators can re-pin a partner's root.** A Re-pin action on a listed partner reads its discovery document again, shows the new fingerprint next to the pinned one, and stores it after the admin confirms the fingerprint and their password, as adding a partner does.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `federated-sharing`: a pinned partner root can be re-pinned by an administrator; a partner with outbound sharing off answers its own reason; `untrusted_root`, `outbound_disabled` and `not_valid_now` stop the save instead of suspending the share.

## Impact

- **Backend:** `FederationPartnerService` (telling a removed partner from one with outbound off, and the re-pin), `FederationPartnerController` and a new route, `FederatedCertificateService::lookup`, `PartnerCertificateBatchService::refusalStatus`, `FederationController`, `FederatedShareService::create` and the controller that answers it. Who may be looked up or shared to does not change; only the reason of the refusal does.
- **Web app:** `src/services/shareSave.js` and its error texts, `src/components/share/FederatedShareForm.vue`, `src/components/settings/FederationPartnersSection.vue`, `l10n/`.
- **Browser extension:** `browser-extension/src/vault/share-save.ts` and `browser-extension/src/messages.ts`.
- **Security:** re-pinning trusts a new root for every share to that partner, so it carries the same checks as adding one: an administrator, Nextcloud password confirmation, and an out-of-band comparison of the fingerprint the admin confirms against the one the partner publishes at that moment. Stopping a save sends nothing to the partner, so the new stops weaken no check: a certificate that fails `untrusted_root` is still never used to encrypt.
- **Not changed:** shares already suspended stay suspended; resuming them is #1262. `unknown_recipient`, `not_a_partner`, `bad_chain` and the other certificate errors still suspend.
- **Depends on** `sharing-atomic-share-save`: this change modifies two federated-sharing requirements as that change states them, so that change is archived first. OpenConnector is not affected.
